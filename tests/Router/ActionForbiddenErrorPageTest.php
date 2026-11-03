<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Tests\Fixtures\FormActionTokens;

/**
 * A form action refused for a bad CSRF field or a token whose signature does
 * not verify answers like `$ctx->abort(403)`: `error.psx` (or the built-in
 * document) with a 403, not the page rendered again with fresh tokens (#132).
 *
 * CsrfToken and the token signing key live in the session, so each test runs
 * in its own process; the GET and the POST share it.
 */
final class ActionForbiddenErrorPageTest extends TestCase
{
    private const FUNCTION_PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\Rosanna\Runtime\Element;
        return function (PageContext $ctx): Closure {
            $save = $ctx->action('save', static function (): void {
                $GLOBALS['action_ran'] = true;
            }, ['id' => 1]);

            return static fn (): Element => new Element('form', ['action' => $save], ['normal page body']);
        };
        PHP;

    private const CLASS_PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ActionForbidden;
        use Polidog\Relayer\Router\Component\PageComponent;
        use Polidog\Rosanna\Runtime\Element;
        final class FormPage extends PageComponent
        {
            public function render(): Element
            {
                return new Element('form', ['action' => $this->action([$this, 'save'], ['id' => 1])], ['normal page body']);
            }

            public function save(array $formData, int $id): void
            {
                $GLOBALS['action_ran'] = true;
            }
        }
        PHP;

    private const ERROR_PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App\ActionForbidden;
        use Polidog\Relayer\Router\Component\ErrorPageComponent;
        use Polidog\Rosanna\Runtime\Element;
        final class ErrorPage extends ErrorPageComponent
        {
            public function render(): Element
            {
                return new Element('h1', [], ['custom error ' . $this->getStatusCode() . ': ' . $this->getMessage()]);
            }
        }
        PHP;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-action-forbidden-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        $_POST = [];
        unset($GLOBALS['action_ran']);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->workDir);
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $GLOBALS['action_ran']);
    }

    #[RunInSeparateProcess]
    public function testFunctionPageBadCsrfRendersTheErrorPage(): void
    {
        $this->writePage(self::FUNCTION_PAGE);
        $this->writeErrorPage();

        $fields = $this->submission($this->request('GET'));
        $html = $this->request('POST', ['_rosanna_csrf' => 'forged'] + $fields);

        $this->assertErrorPage($html);
    }

    #[RunInSeparateProcess]
    public function testFunctionPageTamperedTokenRendersTheErrorPage(): void
    {
        $this->writePage(self::FUNCTION_PAGE);
        $this->writeErrorPage();

        $fields = $this->submission($this->request('GET'));
        $fields['_rosanna_action'] = FormActionTokens::withPayload($fields['_rosanna_action'], [
            'page' => '/',
            'name' => 'save',
            'args' => ['id' => 999],
        ]);
        $html = $this->request('POST', $fields);

        $this->assertErrorPage($html);
    }

    #[RunInSeparateProcess]
    public function testClassPageBadCsrfRendersTheErrorPage(): void
    {
        $this->writePage(self::CLASS_PAGE);
        $this->writeErrorPage();

        $fields = $this->submission($this->request('GET'));
        $html = $this->request('POST', ['_rosanna_csrf' => 'forged'] + $fields);

        $this->assertErrorPage($html);
    }

    #[RunInSeparateProcess]
    public function testClassPageTamperedTokenRendersTheErrorPage(): void
    {
        $this->writePage(self::CLASS_PAGE);
        $this->writeErrorPage();

        $fields = $this->submission($this->request('GET'));
        $fields['_rosanna_action'] = FormActionTokens::withPayload($fields['_rosanna_action'], [
            'class' => 'App\ActionForbidden\FormPage',
            'method' => 'save',
            'args' => ['id' => 999],
        ]);
        $html = $this->request('POST', $fields);

        $this->assertErrorPage($html);
    }

    #[RunInSeparateProcess]
    public function testWithoutErrorPageTheBuiltInDocumentIsRendered(): void
    {
        $this->writePage(self::FUNCTION_PAGE);

        $fields = $this->submission($this->request('GET'));
        $html = $this->request('POST', ['_rosanna_csrf' => 'forged'] + $fields);

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('Forbidden', $html);
        self::assertStringNotContainsString('normal page body', $html);
        self::assertStringNotContainsString('_rosanna_csrf', $html);
        self::assertArrayNotHasKey('action_ran', $GLOBALS);
    }

    private function assertErrorPage(string $html): void
    {
        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('custom error 403: Forbidden', $html);
        self::assertStringNotContainsString('normal page body', $html);
        self::assertStringNotContainsString('_rosanna_csrf', $html);
        self::assertArrayNotHasKey('action_ran', $GLOBALS);
    }

    private function writePage(string $source): void
    {
        \file_put_contents($this->workDir . '/page.php', $source);
    }

    private function writeErrorPage(): void
    {
        \file_put_contents($this->workDir . '/error.php', self::ERROR_PAGE);
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
     * The hidden action and CSRF fields of the rendered form.
     *
     * @return array{_rosanna_action: string, _rosanna_csrf: string}
     */
    private function submission(string $html): array
    {
        \preg_match_all('#<input type="hidden" name="(_rosanna_\w+)" value="([^"]*)"#', $html, $fields, \PREG_SET_ORDER);

        $action = null;
        $csrf = null;
        foreach ($fields as [, $field, $value]) {
            $value = \html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5);

            if ('_rosanna_csrf' === $field) {
                $csrf = $value;
            } elseif (null !== FormAction::peek($value)) {
                $action = $value;
            }
        }

        if (null === $action || null === $csrf) {
            self::fail('No action form in: ' . $html);
        }

        return ['_rosanna_action' => $action, '_rosanna_csrf' => $csrf];
    }
}
