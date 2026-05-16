<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use RuntimeException;

final class MiddlewareDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/mw-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/ping', 0o777, true);
        \file_put_contents(
            $this->workDir . '/ping/route.php',
            "<?php\n\nreturn ['GET' => static fn (): array => ['pong' => true]];\n",
        );
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        $_GET = [];
    }

    public function testNoMiddlewareDispatchesNormally(): void
    {
        self::assertSame('{"pong":true}', $this->dispatch('/ping', 'GET'));
    }

    public function testMiddlewareCallingNextReachesTheRoute(): void
    {
        $this->middleware('return fn (Request $req, Closure $next) => $next($req);');

        self::assertSame('{"pong":true}', $this->dispatch('/ping', 'GET'));
    }

    public function testMiddlewareCanShortCircuitBeforeTheRoute(): void
    {
        $this->middleware(<<<'PHP'
            return function (Request $req, Closure $next): void {
                \http_response_code(418);
                echo 'blocked';
                // no $next() — the route must never run
            };
            PHP);

        $output = $this->dispatch('/ping', 'GET');

        self::assertSame('blocked', $output);
        self::assertSame(418, \http_response_code());
    }

    public function testMiddlewareReceivesTheRequest(): void
    {
        $this->middleware(<<<'PHP'
            return function (Request $req, Closure $next): void {
                echo 'method=' . $req->method;
            };
            PHP);

        self::assertSame('method=GET', $this->dispatch('/ping', 'GET'));
    }

    public function testNonClosureMiddlewareRaisesActionableError(): void
    {
        $this->middleware("return ['not' => 'a closure'];");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a Closure');
        $this->dispatch('/ping', 'GET');
    }

    private function middleware(string $body): void
    {
        \file_put_contents(
            $this->workDir . '/middleware.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Polidog\\Relayer\\Http\\Request;\n\n" . $body . "\n",
        );
    }

    private function dispatch(string $path, string $method): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        \ob_start();

        try {
            AppRouter::create($this->workDir)->run();
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
