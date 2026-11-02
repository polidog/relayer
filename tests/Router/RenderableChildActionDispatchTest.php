<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Tests\Fixtures\ActionChild;

/**
 * A `Renderable` child that registers a server action from its own
 * `render()` only renders when the page's element tree does, after dispatch.
 * Its form has to work anyway (#122): the form is rendered on GET, submitted
 * with the token and CSRF field it carries, and the action runs.
 *
 * CsrfToken and the token signing key live in the session, so each test runs
 * in its own process; the GET and the POST share it.
 */
final class RenderableChildActionDispatchTest extends TestCase
{
    private const PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\Relayer\Tests\Fixtures\ActionChild;
        use Polidog\Relayer\Tests\Fixtures\ResolvingActionChild;
        use Polidog\Rosanna\Html\H;
        use Polidog\Rosanna\Runtime\Element;
        return function (PageContext $ctx): Closure {
            %s
            return static fn (): Element => %s;
        };
        PHP;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-renderable-action-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->workDir));
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    #[DataProvider('provideTheChildActionRunsOnSubmitCases')]
    #[RunInSeparateProcess]
    public function testTheChildActionRunsOnSubmit(string $element, int $children): void
    {
        $this->writePage($element);

        $html = $this->request('GET');
        self::assertSame($children, ActionChild::$renders, 'a GET renders each child once');

        ActionChild::$renders = 0;
        $html = $this->request('POST', $this->submission($html, 'save') + ['title' => 'hello']);

        self::assertSame([['save', ['title' => 'hello']]], ActionChild::$dispatched);
        // Once to register before dispatch, once more for the response.
        self::assertSame(2 * $children, ActionChild::$renders);
        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('dispatched:1', $html);
        self::assertStringContainsString('data-child="save"', $html);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideTheChildActionRunsOnSubmitCases(): iterable
    {
        yield 'direct child' => ["new Element('main', [], [new ActionChild('save')])", 1];

        yield 'in nested arrays' => ["new Element('main', [], [['text'], [[new ActionChild('save')]]])", 1];

        yield 'under nested elements' => ["new Element('main', [], [new Element('div', [], [new Element('section', [], [new ActionChild('save')])])])", 1];

        yield 'in a fragment root' => ["new Element('Fragment', [], [new ActionChild('save')])", 1];

        yield 'inside another child' => ["new Element('main', [], [new ActionChild('outer', [new ActionChild('save')])])", 2];

        yield 'resolved by resolve()' => ["new Element('main', [], [new ResolvingActionChild(new ActionChild('save'))])", 1];

        yield 'in a defer fallback' => ["new Element('main', [], [H::defer('later', fallback: new Element('div', [], [new ActionChild('save')]))])", 1];
    }

    #[RunInSeparateProcess]
    public function testAFactoryActionDoesNotRenderTheChildTwice(): void
    {
        $this->writePage(
            "new Element('main', [], [new Element('form', ['action' => \$factory], []), new ActionChild('save')])",
            "\$factory = \$ctx->action('factory', static function (): void { ActionChild::\$dispatched[] = ['factory', []]; });",
        );

        $html = $this->request('GET');
        ActionChild::$renders = 0;
        $this->request('POST', $this->submission($html, 'factory'));

        self::assertSame([['factory', []]], ActionChild::$dispatched);
        self::assertSame(1, ActionChild::$renders);
    }

    #[RunInSeparateProcess]
    public function testAChildActionCanRedirect(): void
    {
        $this->writePage("new Element('main', [], [new ActionChild('save', redirectTo: '/done')])");

        $html = $this->request('GET');
        ActionChild::$renders = 0;
        $this->request('POST', $this->submission($html, 'save'));

        self::assertSame([['save', []]], ActionChild::$dispatched);
        self::assertSame(303, \http_response_code());
        self::assertSame(1, ActionChild::$renders, 'the redirect skips the response render');
    }

    #[RunInSeparateProcess]
    public function testABadCsrfFieldNeitherRendersTwiceNorRuns(): void
    {
        $this->writePage("new Element('main', [], [new ActionChild('save')])");

        $html = $this->request('GET');
        ActionChild::$renders = 0;
        $this->request('POST', ['_rosanna_csrf' => 'forged'] + $this->submission($html, 'save'));

        self::assertSame([], ActionChild::$dispatched);
        self::assertSame(1, ActionChild::$renders);
    }

    private function writePage(string $element, string $factory = ''): void
    {
        \file_put_contents($this->workDir . '/page.php', \sprintf(self::PAGE, $factory, $element));
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post = []): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = $post;

        \http_response_code(200);
        \ob_start();

        try {
            AppRouter::create($this->workDir)->run();
        } finally {
            $html = (string) \ob_get_clean();
        }

        return $html;
    }

    /**
     * The hidden fields of the rendered form whose action is named `$name`.
     *
     * @return array<string, string>
     */
    private function submission(string $html, string $name): array
    {
        \preg_match_all('#<input type="hidden" name="(_rosanna_\w+)" value="([^"]*)"#', $html, $fields, \PREG_SET_ORDER);

        $csrf = null;
        foreach ($fields as [, $field, $value]) {
            $value = \html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5);

            if ('_rosanna_csrf' === $field) {
                $csrf = $value;
            } elseif ($name === (FormAction::peek($value)['name'] ?? null)) {
                $action = $value;
            }
        }

        if (!isset($action) || null === $csrf) {
            self::fail(\sprintf('No form for action "%s" in: %s', $name, $html));
        }

        return ['_rosanna_action' => $action, '_rosanna_csrf' => $csrf];
    }
}
