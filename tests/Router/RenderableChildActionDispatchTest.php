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
        use Polidog\Relayer\Tests\Fixtures\StatefulActionChild;
        use Polidog\Rosanna\Html\H;
        use Polidog\Rosanna\Runtime\Element;
        return function (PageContext $ctx): Closure {
            %s
            return static fn (): Element => %s;
        };
        PHP;

    private const LAYOUT = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Polidog\Relayer\Tests\Tmp\ActionLayout;
        use Polidog\Relayer\Router\Layout\LayoutComponent;
        use Polidog\Relayer\Tests\Fixtures\ActionChild;
        use Polidog\Relayer\Tests\Fixtures\StatefulActionChild;
        use Polidog\Rosanna\Runtime\Element;
        final class RootLayout extends LayoutComponent
        {
            public function render(): Element
            {
                $this->addJs('/from-layout.js');
                return new Element('div', ['class' => 'layout'], [%s, $this->getChildren()]);
            }
        }
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

    #[RunInSeparateProcess]
    public function testAChildInTheLayoutRunsItsActionOnSubmit(): void
    {
        $this->writeLayout("new ActionChild('save')");
        $this->writePage("new Element('main', [], ['page'])");

        $html = $this->request('GET');
        self::assertSame(1, ActionChild::$renders, 'a GET renders the layout child once');

        ActionChild::$renders = 0;
        $html = $this->request('POST', $this->submission($html, 'save') + ['title' => 'hello']);

        self::assertSame([['save', ['title' => 'hello']]], ActionChild::$dispatched);
        self::assertSame(2, ActionChild::$renders);
        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('dispatched:1', $html);
        self::assertStringContainsString('<main>page</main>', $html);
        self::assertSame(1, \substr_count($html, '<script src="/from-layout.js">'), 'the layout renders twice but declares its script once');
    }

    #[RunInSeparateProcess]
    public function testAChildInsideAChildOfTheLayoutRunsItsActionOnSubmit(): void
    {
        $this->writeLayout("new Element('nav', [], [new ActionChild('outer', [new ActionChild('save')])])");
        $this->writePage("new Element('main', [], ['page'])");

        $html = $this->request('GET');
        $this->request('POST', $this->submission($html, 'save'));

        self::assertSame([['save', []]], ActionChild::$dispatched);
    }

    #[RunInSeparateProcess]
    public function testTheResponseShowsStateTheActionKeptOnTheChild(): void
    {
        $this->writePage("new Element('main', [], [new StatefulActionChild('save', label: 'dispatched:' . \\count(ActionChild::\$dispatched))])");

        $html = $this->request('GET');
        self::assertStringNotContainsString('title is required', $html);

        $html = $this->request('POST', $this->submission($html, 'save') + ['title' => '']);

        self::assertSame([['save', ['title' => '']]], ActionChild::$dispatched);
        self::assertStringContainsString('<p class="error">title is required</p>', $html);
        // Readonly props still come from the render after dispatch.
        self::assertStringContainsString('<p class="label">dispatched:1</p>', $html);
        // The response form posts back to the same action.
        $html = $this->request('POST', $this->submission($html, 'save') + ['title' => '']);
        self::assertSame(1, \substr_count($html, 'title is required'), 'a new request starts from a new child');
    }

    #[RunInSeparateProcess]
    public function testOnlyTheChildAtTheSamePlaceKeepsTheState(): void
    {
        $this->writePage(
            "new Element('main', [], [new StatefulActionChild('first'), new StatefulActionChild('second')])",
        );

        $html = $this->request('GET');
        $html = $this->request('POST', $this->submission($html, 'second') + ['title' => '']);

        self::assertSame(1, \substr_count($html, 'title is required'));
        self::assertMatchesRegularExpression('#data-child="second"[^<]*>(?:(?!</form>).)*title is required#s', $html);
    }

    #[RunInSeparateProcess]
    public function testAChildInTheLayoutKeepsItsState(): void
    {
        $this->writeLayout("new StatefulActionChild('save')");
        $this->writePage("new Element('main', [], ['page'])");

        $html = $this->request('GET');
        $html = $this->request('POST', $this->submission($html, 'save') + ['title' => '']);

        self::assertStringContainsString('<p class="error">title is required</p>', $html);
    }

    #[RunInSeparateProcess]
    public function testTheResponseKeepsTheMetadataTheFactorySet(): void
    {
        $this->writePage(
            "new Element('main', [], [new ActionChild('save')])",
            "\$ctx->metadata(['title' => 'From the factory']);",
        );

        $html = $this->request('GET');
        self::assertStringContainsString('<title>From the factory</title>', $html);

        $html = $this->request('POST', $this->submission($html, 'save'));

        self::assertSame([['save', []]], ActionChild::$dispatched);
        self::assertStringContainsString('<title>From the factory</title>', $html);
    }

    private function writeLayout(string $child): void
    {
        \file_put_contents($this->workDir . '/layout.php', \sprintf(self::LAYOUT, $child));
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
