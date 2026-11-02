<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Native\CgiRunner;
use Polidog\Relayer\Router\Native\GoToolchain;
use Polidog\Relayer\Router\Native\NativeDispatcher;
use Polidog\Relayer\Scaffold\NativeBuildCommand;
use Polidog\Relayer\Tests\Fixtures\SpyLogger;

/**
 * End-to-end for `middleware.go`: a real `go build` of a Proxy, then
 * requests through {@see AppRouter}. Skipped without a Go toolchain (CI
 * installs one).
 */
final class NativeMiddlewareDispatchTest extends TestCase
{
    private const PROXY = <<<'GO'
        package main

        import (
        	"net/http"
        	"os"
        )

        func Proxy(w http.ResponseWriter, r *http.Request) {
        	if r.Header.Get("X-Api-Key") == "" && len(r.URL.Path) >= 4 && r.URL.Path[:4] == "/api" {
        		w.Header().Set("Content-Type", "application/json")
        		w.WriteHeader(401)
        		w.Write([]byte(`{"error":"missing api key"}`))
        		return
        	}
        	if r.URL.Query().Get("rewrite") != "" {
        		w.Header().Set("Relayer-Rewrite-Path", r.URL.Query().Get("rewrite"))
        	}
        	w.Header().Set("Relayer-Next", "1")
        	w.Header().Set("Relayer-Request-Header-X-User", "42")
        	w.Header().Set("X-Proxied-By", "go")
        	w.Header().Set("X-Matched", os.Getenv("RELAYER_MATCHED_PATTERN"))
        }
        GO;

    private string $root = '';

    private SpyLogger $logger;

    protected function setUp(): void
    {
        if (null === GoToolchain::locate()) {
            self::markTestSkipped('Go toolchain not available (install Go or set GO_BIN)');
        }

        $this->root = \sys_get_temp_dir() . '/native-mw-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/src/Pages/api/whoami', 0o777, true);
        \mkdir($this->root . '/src/Pages/about', 0o777, true);
        \file_put_contents(
            $this->root . '/src/Pages/api/whoami/route.php',
            "<?php\n\nuse Polidog\\Relayer\\Http\\Request;\nuse Polidog\\Relayer\\Http\\Response;\n\n"
            . "return ['GET' => static fn (Request \$r): Response => Response::json(['user' => \$r->header('x-user')])];\n",
        );
        \file_put_contents(
            $this->root . '/src/Pages/about/page.php',
            "<?php\n\nuse Polidog\\Rosanna\\Runtime\\Element;\n\n"
            . "return static fn (): Element => new Element('p', [], ['about page']);\n",
        );
        \file_put_contents($this->root . '/src/Pages/middleware.go', self::PROXY);

        $this->logger = new SpyLogger();
        \http_response_code(200);
        $this->clearRequestHeaders();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->root) {
            self::rmrf($this->root);
        }
        $this->clearRequestHeaders();
    }

    public function testBuildProducesTheMiddlewareBinary(): void
    {
        $out = $this->build();

        self::assertStringContainsString('built  middleware  -> ' . Relayer::NATIVE_BIN_DIR . '/middleware', $out);
        self::assertFileExists($this->root . '/' . Relayer::NATIVE_BIN_DIR . '/middleware');
    }

    public function testShortCircuitsWithoutRunningTheRoute(): void
    {
        $this->build();

        $out = $this->dispatch('GET', '/api/whoami');

        self::assertSame('{"error":"missing api key"}', $out);
        self::assertSame(401, \http_response_code());
    }

    public function testRelayerNextContinuesToTheRouteWithTheInjectedHeader(): void
    {
        $this->build();

        $out = $this->dispatch('GET', '/api/whoami', ['X-Api-Key' => 'x']);

        self::assertSame('{"user":"42"}', $out);
        self::assertSame(200, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            $headers = self::sentHeaders();
            self::assertContains('X-Proxied-By: go', $headers);
            self::assertContains('X-Matched: /api/whoami', $headers);
            foreach ($headers as $header) {
                self::assertStringNotContainsStringIgnoringCase('relayer-', $header);
            }
        }
    }

    public function testContinuesToAPageToo(): void
    {
        $this->build();

        $out = $this->dispatch('GET', '/about');

        self::assertStringContainsString('about page', $out);
        self::assertSame(200, \http_response_code());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('X-Proxied-By: go', \xdebug_get_headers());
        }
    }

    public function testRewritePathMatchesAnotherRoute(): void
    {
        $this->build();

        $out = $this->dispatch('GET', '/anything-else?rewrite=/about');

        self::assertStringContainsString('about page', $out);
        self::assertSame(200, \http_response_code());
    }

    public function testBothMiddlewareFilesIsAnError(): void
    {
        $this->build();
        \file_put_contents($this->root . '/src/Pages/middleware.php', "<?php\n\nreturn fn (\$r, \$next) => \$next(\$r);\n");

        $output = $this->dispatch('GET', '/about', debug: true);

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('One middleware per app', $output);
        self::assertStringContainsString('middleware.php', $output);
        self::assertStringContainsString('middleware.go', $output);
    }

    public function testUnbuiltMiddlewareIsAJson500(): void
    {
        $out = $this->dispatch('GET', '/about');

        self::assertSame(500, \http_response_code());
        self::assertJson($out);
        self::assertStringContainsString('relayer native:build', $out);
    }

    public function testPanicIsAJson500AndLogged(): void
    {
        \file_put_contents($this->root . '/src/Pages/middleware.go', <<<'GO'
            package main

            import "net/http"

            func Proxy(w http.ResponseWriter, r *http.Request) {
            	panic("boom")
            }
            GO);
        $this->build();

        $out = $this->dispatch('GET', '/about');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('Native middleware failed', $out);
        $errors = $this->logger->withLevel('error');
        self::assertCount(1, $errors);
        $stderr = $errors[0]['context']['stderr'] ?? null;
        self::assertIsString($stderr);
        self::assertStringContainsString('panic: boom', $stderr);
    }

    private function build(): string
    {
        $lines = [];
        $status = NativeBuildCommand::run([], static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, $this->root);
        $out = \implode("\n", $lines);
        self::assertSame(0, $status, $out);

        return $out;
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $uri, array $headers = [], bool $debug = false): string
    {
        $this->clearRequestHeaders();
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }
        $_GET = [];
        $query = \parse_url($uri, \PHP_URL_QUERY);
        if (\is_string($query)) {
            \parse_str($query, $_GET);
        }

        $dispatcher = new NativeDispatcher(
            $this->root . '/' . Relayer::NATIVE_BIN_DIR,
            new CgiRunner(),
            true,
            $this->logger,
        );
        $app = AppRouter::create($this->root . '/src/Pages')->setNativeDispatcher($dispatcher)->setDebug($debug);

        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    /**
     * @return list<string>
     */
    private static function sentHeaders(): array
    {
        $headers = [];
        foreach (\xdebug_get_headers() as $header) {
            if (\is_string($header)) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    private function clearRequestHeaders(): void
    {
        foreach (\array_keys($_SERVER) as $key) {
            if (\is_string($key) && \str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
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
