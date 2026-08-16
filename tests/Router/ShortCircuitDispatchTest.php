<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\StopRequest;

/**
 * The two dispatch branches that answer a request without rendering a page:
 * the `304 Not Modified` reply to a conditional GET, and the PRG redirect
 * that follows a `useState` action.
 *
 * Both used to call `exit`. Under FrankenPHP's worker mode that terminates
 * the worker script — the booted application is thrown away and restarted
 * on every 304 and every form POST — so they now unwind through
 * {@see StopRequest} instead. These tests pin that
 * down: `run()` has to *return* on both branches, having produced exactly
 * the short-circuit response and no page body.
 *
 * The assertions after `run()` are themselves the regression check: if the
 * branch ever calls `exit` again, the PHPUnit child process dies there and
 * the test cannot report a result.
 */
final class ShortCircuitDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/short-circuit-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        $_POST = [];
        \http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_IF_NONE_MATCH'],
            $_SERVER['HTTP_X_USEPHP_PARTIAL'],
        );
    }

    #[RunInSeparateProcess]
    public function testConditionalGetAnswers304AndReturnsFromRun(): void
    {
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\Relayer\Http\Cache;
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Runtime\Element;

                return function (PageContext $ctx): Closure {
                    $ctx->cache(new Cache(maxAge: 60, etag: 'v1'));

                    return fn (): Element => new Element('p', [], ['page body']);
                };
                PHP,
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"v1"';

        $output = $this->dispatch();

        self::assertSame(304, \http_response_code());
        // A 304 carries no body — the render closure must never run.
        self::assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function testStateActionRedirectsAndReturnsFromRun(): void
    {
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\UsePhp\Runtime\Element;

                return fn (): Closure => fn (): Element => new Element('p', [], ['page body']);
                PHP,
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/?q=1';
        $_POST = [
            // Root page → pageId '/' → component id 'page:/'.
            '_usephp_component' => 'page:/',
            '_usephp_action' => '{"type":"setState","payload":{"index":0,"value":"x"}}',
        ];

        $output = $this->dispatch();

        // The redirect IS the response: the render is abandoned mid-way,
        // so nothing of the page may reach the client.
        self::assertSame('', $output);
        self::assertStringNotContainsString('page body', $output);
    }

    private function dispatch(): string
    {
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
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $child = $path . '/' . $entry;
            \is_dir($child) ? $this->rmrf($child) : \unlink($child);
        }
        \rmdir($path);
    }
}
