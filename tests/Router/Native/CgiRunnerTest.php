<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Native\CgiRunner;
use Polidog\Relayer\Router\Native\NativeProcessException;

/**
 * Exercises the process half of native routes without a Go toolchain:
 * the "binary" is a shell script that speaks CGI on stdout.
 */
final class CgiRunnerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/cgi-runner-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->workDir);
    }

    public function testEnvironmentCarriesTheCgiMetaVariables(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/api/users/42',
            headers: [
                'host' => 'example.com:8443',
                'content-type' => 'application/json',
                'content-length' => '999',
                'x-probe' => 'seen',
                'accept-language' => 'ja',
                'proxy' => 'http://evil.example',
            ],
            uri: '/api/users/42?x=1&y=2',
            body: '{"a":1}',
            ip: '10.0.0.9',
            host: 'example.com:8443',
            scheme: 'https',
        );

        $env = (new CgiRunner())->environment($request, '{"a":1}', ['RELAYER_ROUTE_PARAMS' => '{"id":"42"}']);

        self::assertSame('CGI/1.1', $env['GATEWAY_INTERFACE']);
        self::assertSame('HTTP/1.1', $env['SERVER_PROTOCOL']);
        self::assertSame('POST', $env['REQUEST_METHOD']);
        self::assertSame('/api/users/42?x=1&y=2', $env['REQUEST_URI']);
        self::assertSame('', $env['SCRIPT_NAME']);
        self::assertSame('/api/users/42', $env['PATH_INFO']);
        self::assertSame('x=1&y=2', $env['QUERY_STRING']);
        self::assertSame('application/json', $env['CONTENT_TYPE']);
        // Always the real body length, never the client's claim.
        self::assertSame('7', $env['CONTENT_LENGTH']);
        self::assertSame('example.com', $env['SERVER_NAME']);
        self::assertSame('8443', $env['SERVER_PORT']);
        self::assertSame('on', $env['HTTPS']);
        self::assertSame('10.0.0.9', $env['REMOTE_ADDR']);

        self::assertSame('seen', $env['HTTP_X_PROBE']);
        self::assertSame('ja', $env['HTTP_ACCEPT_LANGUAGE']);
        self::assertSame('example.com:8443', $env['HTTP_HOST']);
        self::assertArrayNotHasKey('HTTP_CONTENT_TYPE', $env);
        self::assertArrayNotHasKey('HTTP_CONTENT_LENGTH', $env);
        // httpoxy: a client `Proxy:` header must never become HTTP_PROXY.
        self::assertArrayNotHasKey('HTTP_PROXY', $env);

        self::assertSame('{"id":"42"}', $env['RELAYER_ROUTE_PARAMS']);
    }

    public function testFrameworkVariablesCannotBeSpoofedByHeaders(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/x',
            headers: ['relayer-route-params' => '{"id":"evil"}'],
        );

        $env = (new CgiRunner())->environment($request, '', ['RELAYER_ROUTE_PARAMS' => '{"id":"1"}']);

        self::assertSame('{"id":"1"}', $env['RELAYER_ROUTE_PARAMS']);
        self::assertSame('{"id":"evil"}', $env['HTTP_RELAYER_ROUTE_PARAMS']);
    }

    public function testPlainHttpDefaultsPortAndOmitsHttps(): void
    {
        $env = (new CgiRunner())->environment(new Request('GET', '/', host: 'localhost'), '');

        self::assertSame('localhost', $env['SERVER_NAME']);
        self::assertSame('80', $env['SERVER_PORT']);
        self::assertArrayNotHasKey('HTTPS', $env);
        self::assertSame('0', $env['CONTENT_LENGTH']);
    }

    public function testParsesTheChildsCgiOutput(): void
    {
        $script = $this->script(<<<'SH'
            printf 'Status: 201 Created\r\nContent-Type: text/plain\r\nX-Method: %s\r\nX-Params: %s\r\n\r\n' "$REQUEST_METHOD" "$RELAYER_ROUTE_PARAMS"
            printf 'created'
            SH);

        $run = (new CgiRunner())->run($script, new Request('PUT', '/x'), ['RELAYER_ROUTE_PARAMS' => '{"id":"7"}']);

        self::assertSame(201, $run->response->status);
        self::assertSame('text/plain', $run->response->header('content-type'));
        self::assertSame('PUT', $run->response->header('x-method'));
        self::assertSame('{"id":"7"}', $run->response->header('x-params'));
        self::assertSame('created', $run->response->body);
        self::assertSame('', $run->stderr);
    }

    public function testStreamsALargeBodyToStdinWithoutDeadlocking(): void
    {
        // Echo the body back: the child writes as much as it reads, so a
        // naive write-everything-then-read would wedge past the pipe buffer.
        $script = $this->script("printf 'Content-Type: application/octet-stream\\n\\n'; cat");
        $body = \str_repeat("0123456789abcdef\n", 40_000); // ~680 KiB

        $run = (new CgiRunner())->run($script, new Request('POST', '/echo', body: $body));

        self::assertSame(200, $run->response->status);
        self::assertSame(\strlen($body), \strlen($run->response->body));
        self::assertSame(\md5($body), \md5($run->response->body));
    }

    public function testStderrIsReturnedOnSuccess(): void
    {
        $script = $this->script("echo 'note to operator' >&2; printf 'Content-Type: text/plain\\n\\nok'");

        $run = (new CgiRunner())->run($script, new Request('GET', '/'));

        self::assertSame('ok', $run->response->body);
        self::assertSame("note to operator\n", $run->stderr);
    }

    public function testNonZeroExitRaisesWithStderrAttached(): void
    {
        $script = $this->script("echo 'panic: boom' >&2; exit 3");

        try {
            (new CgiRunner())->run($script, new Request('GET', '/'));
            self::fail('expected a NativeProcessException');
        } catch (NativeProcessException $e) {
            self::assertStringContainsString('exited with status 3', $e->getMessage());
            self::assertSame(3, $e->exitCode);
            self::assertSame("panic: boom\n", $e->stderr);
        }
    }

    public function testMalformedOutputRaises(): void
    {
        $script = $this->script("printf 'this is not cgi'");

        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('not a CGI response');
        (new CgiRunner())->run($script, new Request('GET', '/'));
    }

    public function testTimeoutKillsTheChildAndRaises(): void
    {
        $script = $this->script("sleep 5; printf 'Content-Type: text/plain\\n\\nlate'");

        $started = \microtime(true);

        try {
            (new CgiRunner(1))->run($script, new Request('GET', '/'));
            self::fail('expected a timeout');
        } catch (NativeProcessException $e) {
            self::assertStringContainsString('exceeded the 1 second timeout', $e->getMessage());
            self::assertStringContainsString('NATIVE_ROUTE_TIMEOUT', $e->getMessage());
        }

        self::assertLessThan(4.0, \microtime(true) - $started, 'the child must be killed, not waited for');
    }

    public function testUnstartableBinaryRaises(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('could not start');
        (new CgiRunner())->run($this->workDir . '/does-not-exist', new Request('GET', '/'));
    }

    private function script(string $body): string
    {
        $path = $this->workDir . '/handler-' . \bin2hex(\random_bytes(3));
        \file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        \chmod($path, 0o755);

        return $path;
    }
}
