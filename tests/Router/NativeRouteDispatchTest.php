<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Native\CgiRunner;
use Polidog\Relayer\Router\Native\GoToolchain;
use Polidog\Relayer\Router\Native\NativeDispatcher;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Scaffold\NativeBuildCommand;
use Polidog\Relayer\Tests\Fixtures\SpyLogger;

/**
 * End-to-end: a real `go build` of the native-app fixture, then requests
 * dispatched through {@see AppRouter} into the Go binaries. Skipped when
 * no Go toolchain is available (CI installs one, so it runs there).
 */
final class NativeRouteDispatchTest extends TestCase
{
    /** A project root whose fixture routes have been built once for the class. */
    private static string $built = '';

    /** @var list<string> */
    private static array $buildLog = [];

    private string $workDir = '';

    private SpyLogger $logger;

    public static function setUpBeforeClass(): void
    {
        if (null === GoToolchain::locate()) {
            return;
        }

        self::$built = self::newProject();
        $status = NativeBuildCommand::run([], static function (string $line): void {
            self::$buildLog[] = $line;
        }, self::$built);

        if (0 !== $status) {
            self::fail("native:build failed:\n" . \implode("\n", self::$buildLog));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$built) {
            self::rmrf(self::$built);
            self::$built = '';
        }
    }

    protected function setUp(): void
    {
        if (null === GoToolchain::locate()) {
            self::markTestSkipped('Go toolchain not available (install Go or set GO_BIN)');
        }

        $this->logger = new SpyLogger();
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
        $this->clearRequestHeaders();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->workDir) {
            self::rmrf($this->workDir);
            $this->workDir = '';
        }
        $this->clearRequestHeaders();
        $_POST = [];
        $_GET = [];
    }

    public function testBuildReportsEveryRoute(): void
    {
        $log = \implode("\n", self::$buildLog);

        self::assertStringContainsString('built  /api/hello  -> ' . Relayer::NATIVE_BIN_DIR . '/api__hello', $log);
        self::assertStringContainsString('built  /api/users/[id]  -> ' . Relayer::NATIVE_BIN_DIR . '/api__users__[id]', $log);
        self::assertFileExists(self::$built . '/' . Relayer::NATIVE_BIN_DIR . '/api__hello');
    }

    public function testGetReturnsTheHandlersJson(): void
    {
        $out = $this->dispatch(self::$built, 'GET', '/api/hello');

        self::assertSame("{\"message\":\"Hello from Go!\"}\n", $out);
        self::assertSame(200, \http_response_code());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Content-Type: application/json', \xdebug_get_headers());
        }
    }

    public function testUndeclaredMethodIs405WithAllow(): void
    {
        $out = $this->dispatch(self::$built, 'POST', '/api/hello');

        self::assertSame('{"error":"Method Not Allowed"}', $out);
        self::assertSame(405, \http_response_code());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Allow: GET, HEAD, OPTIONS', \xdebug_get_headers());
        }
    }

    public function testOptionsIsSynthesized(): void
    {
        $out = $this->dispatch(self::$built, 'OPTIONS', '/api/hello');

        self::assertSame('', $out);
        self::assertSame(204, \http_response_code());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Allow: GET, HEAD, OPTIONS', \xdebug_get_headers());
        }
    }

    public function testHeadRunsGetWithoutABody(): void
    {
        $out = $this->dispatch(self::$built, 'HEAD', '/api/hello');

        self::assertSame('', $out);
        self::assertSame(200, \http_response_code());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Content-Type: application/json', \xdebug_get_headers());
        }
    }

    public function testParamsPatternHeadersAndQueryReachGo(): void
    {
        $out = $this->dispatch(self::$built, 'GET', '/api/users/42?page=2', ['X-Probe' => 'hello-go']);

        self::assertSame('{"id":"42"}', $out);
        if (\function_exists('xdebug_get_headers')) {
            $headers = \xdebug_get_headers();
            self::assertContains('X-Route-Pattern: /api/users/[id]', $headers);
            self::assertContains('X-Seen-Header: hello-go', $headers);
            self::assertContains('X-Query: page=2', $headers);
        }
    }

    public function testRequestBodyReachesGo(): void
    {
        // A CLI test has an empty php://input, so feed the body through a
        // constructed Request straight into the dispatcher.
        $route = null;
        foreach ((new PageScanner(self::$built . '/src/Pages'))->scan() as $candidate) {
            if ('/api/users/[id]' === $candidate->pattern) {
                $route = $candidate;
            }
        }
        self::assertNotNull($route);

        $dispatcher = new NativeDispatcher(self::$built . '/' . Relayer::NATIVE_BIN_DIR, new CgiRunner());
        $request = new Request('POST', '/api/users/42', body: 'payload', host: 'localhost');

        \ob_start();

        try {
            $dispatcher->dispatch($route, ['id' => '42'], $request);
        } finally {
            $out = (string) \ob_get_clean();
        }

        self::assertSame('got:payload', $out);
        self::assertSame(201, \http_response_code());
    }

    public function testUnbuiltRouteIsAJson500NamingTheBuildCommand(): void
    {
        $this->workDir = self::newProject();

        $out = $this->dispatch($this->workDir, 'GET', '/api/hello');

        self::assertSame(500, \http_response_code());
        self::assertJson($out);
        self::assertStringContainsString('relayer native:build', $out);
        self::assertStringContainsString('/api/hello', $out);
        self::assertStringNotContainsString('<html', $out);
    }

    public function testStaleBinaryIsRefusedInDevAndServedInProd(): void
    {
        $this->workDir = self::newProject();
        self::copyTree(self::$built . '/var', $this->workDir . '/var');
        // The source is newer than the binary we just copied.
        \touch($this->workDir . '/src/Pages/api/hello/route.go', \time() + 60);

        $dev = $this->dispatch($this->workDir, 'GET', '/api/hello', dev: true);
        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('route.go is newer than the binary', $dev);
        self::assertStringContainsString('relayer native:build', $dev);

        $prod = $this->dispatch($this->workDir, 'GET', '/api/hello', dev: false);
        self::assertSame(200, \http_response_code());
        self::assertSame("{\"message\":\"Hello from Go!\"}\n", $prod);
    }

    public function testPanicInGoIsAJson500AndLoggedAsError(): void
    {
        $this->workDir = self::newProject();
        \file_put_contents($this->workDir . '/src/Pages/api/hello/route.go', <<<'GO'
            package main

            import "net/http"

            func GET(w http.ResponseWriter, r *http.Request) {
            	panic("boom")
            }
            GO);
        $status = NativeBuildCommand::run([], static function (string $line): void {}, $this->workDir);
        self::assertSame(0, $status);

        $out = $this->dispatch($this->workDir, 'GET', '/api/hello');

        self::assertSame(500, \http_response_code());
        self::assertJson($out);
        self::assertStringContainsString('Native route /api/hello failed', $out);

        $errors = $this->logger->withLevel('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('/api/hello', $errors[0]['message']);
        $stderr = $errors[0]['context']['stderr'] ?? null;
        self::assertIsString($stderr);
        self::assertStringContainsString('panic: boom', $stderr);
    }

    public function testTimeoutIsAJson500(): void
    {
        $this->workDir = self::newProject();
        \file_put_contents($this->workDir . '/src/Pages/api/hello/route.go', <<<'GO'
            package main

            import (
            	"net/http"
            	"time"
            )

            func GET(w http.ResponseWriter, r *http.Request) {
            	time.Sleep(5 * time.Second)
            }
            GO);
        self::assertSame(0, NativeBuildCommand::run([], static function (string $line): void {}, $this->workDir));

        $out = $this->dispatch($this->workDir, 'GET', '/api/hello', timeout: 1);

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('exceeded the 1 second timeout', $out);
    }

    public function testRootMiddlewareWrapsNativeRoutes(): void
    {
        // Unbuilt project: if the middleware did not short-circuit we would
        // see the "not built" 500 instead.
        $this->workDir = self::newProject();
        \file_put_contents($this->workDir . '/src/Pages/middleware.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Polidog\Relayer\Http\Request;

            return function (Request $req, Closure $next): void {
                \http_response_code(418);
                echo 'blocked by middleware';
            };
            PHP);

        $out = $this->dispatch($this->workDir, 'GET', '/api/hello');

        self::assertSame('blocked by middleware', $out);
        self::assertSame(418, \http_response_code());
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatch(
        string $root,
        string $method,
        string $uri,
        array $headers = [],
        bool $dev = true,
        int $timeout = CgiRunner::DEFAULT_TIMEOUT,
    ): string {
        $this->clearRequestHeaders();
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        $logger = $this->logger;

        $dispatcher = new NativeDispatcher(
            $root . '/' . Relayer::NATIVE_BIN_DIR,
            new CgiRunner($timeout),
            $dev,
            $logger,
        );

        $app = AppRouter::create($root . '/src/Pages')->setNativeDispatcher($dispatcher);

        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function clearRequestHeaders(): void
    {
        foreach (\array_keys($_SERVER) as $key) {
            if (\is_string($key) && \str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
    }

    private static function newProject(): string
    {
        $root = \sys_get_temp_dir() . '/native-dispatch-' . \bin2hex(\random_bytes(6));
        \mkdir($root . '/src', 0o777, true);
        self::copyTree(__DIR__ . '/fixtures/native-app', $root . '/src/Pages');

        return $root;
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!\is_dir($to)) {
            \mkdir($to, 0o777, true);
        }
        foreach (\scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            if (\is_dir($src)) {
                self::copyTree($src, $dst);
            } else {
                \copy($src, $dst);
                \chmod($dst, \fileperms($src) & 0o777);
            }
        }
    }

    private static function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
