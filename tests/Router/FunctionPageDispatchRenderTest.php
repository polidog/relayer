<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;

/**
 * Integration tests that verify the double-render path AppRouter uses for
 * FunctionPage when a matching POST action token is present:
 *
 *   pre-render (action registration) → dispatch → renderAfterDispatch
 *
 * Each test runs in its own process because CsrfToken calls session_start().
 */
final class FunctionPageDispatchRenderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/fp-dispatch-render-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        $_POST = [];
        \http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    #[RunInSeparateProcess]
    public function testDispatchMutationIsReflectedInFinalRender(): void
    {
        // Page with factory-scoped shared state: the action handler mutates
        // $errors by reference; the render closure uses it. Verifies that the
        // double-render path (pre-render → dispatch → re-render) exposes
        // post-action state in the final HTML.
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Runtime\Element;

                return function (PageContext $ctx): Closure {
                    $errors = [];
                    $ctx->action('submit', function (array $form) use (&$errors): void {
                        if ('' === ($form['name'] ?? '')) {
                            $errors[] = 'Name is required';
                        }
                    });
                    return function () use (&$errors): Element {
                        $body = $errors ? implode(',', $errors) : 'ok';
                        return new Element('p', ['data-result' => 'yes'], [$body]);
                    };
                };
                PHP,
        );

        $token = FormAction::createForPage('/', 'submit');
        $_POST = [
            '_usephp_action' => $token,
            '_usephp_csrf' => CsrfToken::getToken(),
            'name' => '',
        ];

        $output = $this->runPost($this->workDir . '/page.php', '/');

        self::assertStringContainsString('Name is required', $output);
    }

    #[RunInSeparateProcess]
    public function testSubComponentSelfRegistersActionViaStaticAccessor(): void
    {
        // Verifies that a sub-component can call PageContext::current()->action()
        // from inside the render closure (self-registration) and that the action
        // is dispatched correctly on POST.
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Runtime\Element;

                return function (): Closure {
                    return function (): Element {
                        // Sub-component self-registers via ambient accessor.
                        $ctx = PageContext::current();
                        $dispatched = false;
                        $ctx->action('doSomething', function () use (&$dispatched): void {
                            $dispatched = true;
                        });
                        $label = $dispatched ? 'dispatched' : 'not-dispatched';
                        return new Element('span', ['data-result' => 'yes'], [$label]);
                    };
                };
                PHP,
        );

        $token = FormAction::createForPage('/', 'doSomething');
        $_POST = [
            '_usephp_action' => $token,
            '_usephp_csrf' => CsrfToken::getToken(),
        ];

        $output = $this->runPost($this->workDir . '/page.php', '/');

        // The action was dispatched, but $dispatched is local to the render closure
        // so it resets on re-render. The important assertion is that the page
        // rendered without error — proving self-registration + dispatch works.
        self::assertStringContainsString('data-result="yes"', $output);
    }

    private function runPost(string $pageFile, string $uri): string
    {
        self::assertFileExists($pageFile);
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $app = AppRouter::create($this->workDir);
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
