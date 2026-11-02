<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\StopRequest;

/**
 * The dispatch branch that answers a request without rendering a page: the
 * `304 Not Modified` reply to a conditional GET.
 *
 * It used to call `exit`. Under FrankenPHP's worker mode that terminates the
 * worker script — the booted application is thrown away and restarted on
 * every 304 — so it now unwinds through {@see StopRequest} instead. This test
 * pins that down: `run()` has to *return*, having produced exactly the
 * short-circuit response and no page body.
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
                use Polidog\Rosanna\Runtime\Element;

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
