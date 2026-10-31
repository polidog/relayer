<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Native\CgiRunner;
use Polidog\Relayer\Router\Native\NativeProxy;
use Polidog\Relayer\Tests\Fixtures\SpyLogger;

/**
 * The continue protocol on top of CGI, exercised with shell-script
 * "binaries" so no Go toolchain is needed.
 */
final class NativeProxyTest extends TestCase
{
    private string $workDir;

    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/native-proxy-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        $this->logger = new SpyLogger();
        \http_response_code(200);
        if (\function_exists('header_remove')) {
            \header_remove();
        }
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->workDir);
    }

    public function testRelayerNextContinuesWithRewrittenPathAndHeaders(): void
    {
        $proxy = $this->proxy(<<<'SH'
            printf 'Relayer-Next: 1\r\n'
            printf 'Relayer-Rewrite-Path: /about\r\n'
            printf 'Relayer-Request-Header-X-User: 42\r\n'
            printf 'relayer-request-header-x-role: admin\r\n'
            printf 'X-Proxied-By: go\r\n'
            printf 'Set-Cookie: a=1\r\n'
            printf 'Set-Cookie: b=2\r\n'
            printf 'Content-Type: text/plain\r\n\r\nignored body'
            SH);

        $seen = null;
        $out = $this->invoke($proxy, new Request('GET', '/anything', headers: ['x-role' => 'guest']), static function (Request $request) use (&$seen): void {
            $seen = $request;
            echo 'route ran';
        });

        self::assertSame('route ran', $out, 'the proxy body is ignored on continue');
        self::assertInstanceOf(Request::class, $seen);
        self::assertSame('/about', $seen->path);
        self::assertSame('42', $seen->header('x-user'));
        self::assertSame('admin', $seen->header('X-Role'), 'request header replaced case-insensitively');
        self::assertSame(200, \http_response_code(), 'Status: is not applied on continue');

        if (\function_exists('xdebug_get_headers')) {
            $headers = self::sentHeaders();
            self::assertContains('X-Proxied-By: go', $headers);
            self::assertContains('Set-Cookie: a=1', $headers);
            self::assertContains('Set-Cookie: b=2', $headers, 'repeated Set-Cookie is appended, not replaced');
            foreach ($headers as $header) {
                self::assertStringNotContainsStringIgnoringCase('relayer-', $header, 'protocol headers never reach the client');
            }
        }
    }

    public function testRewritePathWithoutLeadingSlashIsNormalized(): void
    {
        $proxy = $this->proxy("printf 'Relayer-Next: 1\\r\\nRelayer-Rewrite-Path: about\\r\\n\\r\\n'");

        $seen = null;
        $this->invoke($proxy, new Request('GET', '/x'), static function (Request $request) use (&$seen): void {
            $seen = $request;
        });

        self::assertInstanceOf(Request::class, $seen);
        self::assertSame('/about', $seen->path);
    }

    public function testWithoutRelayerNextTheProxyResponseIsFinal(): void
    {
        $proxy = $this->proxy(<<<'SH'
            printf 'Status: 401 Unauthorized\r\n'
            printf 'Content-Type: application/json\r\n'
            printf 'Relayer-Request-Header-X-User: 42\r\n'
            printf 'WWW-Authenticate: Bearer\r\n\r\n'
            printf '{"error":"missing api key"}'
            SH);

        $nextCalled = false;
        $out = $this->invoke($proxy, new Request('GET', '/api/x'), static function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        self::assertFalse($nextCalled, 'short-circuit: the route must not run');
        self::assertSame('{"error":"missing api key"}', $out);
        self::assertSame(401, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            $headers = self::sentHeaders();
            self::assertContains('WWW-Authenticate: Bearer', $headers);
            self::assertContains('Content-Type: application/json', $headers);
            foreach ($headers as $header) {
                self::assertStringNotContainsStringIgnoringCase('relayer-', $header);
            }
        }
    }

    public function testMatchedPatternIsPassedToTheProxy(): void
    {
        $proxy = $this->proxy("printf 'Content-Type: text/plain\\r\\n\\r\\n%s' \"[\$RELAYER_MATCHED_PATTERN]\"", '/api/users/[id]');

        $out = $this->invoke($proxy, new Request('GET', '/api/users/1'), static function (): void {});

        self::assertSame('[/api/users/[id]]', $out);
    }

    public function testNoMatchPassesAnEmptyPattern(): void
    {
        $proxy = $this->proxy("printf 'Content-Type: text/plain\\r\\n\\r\\n%s' \"[\$RELAYER_MATCHED_PATTERN]\"", null);

        self::assertSame('[]', $this->invoke($proxy, new Request('GET', '/nope'), static function (): void {}));
    }

    public function testRouteVariablesAreNotPassedToTheProxy(): void
    {
        $proxy = $this->proxy("printf 'Content-Type: text/plain\\r\\n\\r\\n%s' \"[\${RELAYER_ROUTE_PARAMS-unset}][\${RELAYER_ROUTE_PATTERN-unset}]\"");

        self::assertSame('[unset][unset]', $this->invoke($proxy, new Request('GET', '/'), static function (): void {}));
    }

    public function testUnbuiltMiddlewareIsAJson500NamingTheBuildCommand(): void
    {
        $proxy = new NativeProxy(
            $this->workDir . '/missing-binary',
            $this->workDir . '/middleware.go',
            new CgiRunner(),
            static fn (): ?string => null,
        );

        $nextCalled = false;
        $out = $this->invoke($proxy, new Request('GET', '/'), static function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        self::assertFalse($nextCalled);
        self::assertSame(500, \http_response_code());
        self::assertJson($out);
        self::assertStringContainsString('relayer native:build', $out);
        self::assertStringContainsString('middleware.go', $out);
    }

    public function testProcessFailureIsAJson500AndLogged(): void
    {
        $proxy = $this->proxy("echo 'panic: boom' >&2; exit 2");

        $out = $this->invoke($proxy, new Request('GET', '/'), static function (): void {});

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('Native middleware failed: handler exited with status 2', $out);

        $errors = $this->logger->withLevel('error');
        self::assertCount(1, $errors);
        self::assertSame("panic: boom\n", $errors[0]['context']['stderr'] ?? null);
    }

    public function testStaleBinaryIsRefusedOnlyWithDevHints(): void
    {
        $script = $this->script("printf 'Relayer-Next: 1\\r\\n\\r\\n'");
        $source = $this->workDir . '/middleware.go';
        \file_put_contents($source, "package main\n");
        \touch($source, \time() + 60);

        $dev = new NativeProxy($script, $source, new CgiRunner(), static fn (): ?string => null, true);
        $out = $this->invoke($dev, new Request('GET', '/'), static function (): void {
            echo 'continued';
        });
        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('middleware.go is newer than the binary', $out);

        \http_response_code(200);
        $prod = new NativeProxy($script, $source, new CgiRunner(), static fn (): ?string => null, false);
        self::assertSame('continued', $this->invoke($prod, new Request('GET', '/'), static function (): void {
            echo 'continued';
        }));
    }

    private function proxy(string $scriptBody, ?string $matchedPattern = null): NativeProxy
    {
        return new NativeProxy(
            $this->script($scriptBody),
            $this->workDir . '/middleware.go',
            new CgiRunner(),
            static fn (): ?string => $matchedPattern,
            false,
            $this->logger,
        );
    }

    /**
     * @param callable(Request): void $next
     */
    private function invoke(NativeProxy $proxy, Request $request, callable $next): string
    {
        $middleware = $proxy->middleware();

        \ob_start();

        try {
            $middleware($request, $next(...));
        } finally {
            $out = (string) \ob_get_clean();
        }

        return $out;
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

    private function script(string $body): string
    {
        $path = $this->workDir . '/proxy-' . \bin2hex(\random_bytes(3));
        \file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        \chmod($path, 0o755);

        return $path;
    }
}
