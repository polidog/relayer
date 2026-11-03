<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Bodiless 204 / 304 responses carry no `Content-Type` (issue #135), over
 * real HTTP behind a real `php -S`: PHP adds its `default_mimetype`
 * (`text/html; charset=UTF-8`) only when the SAPI sends the headers, so
 * neither in-process dispatch nor Xdebug's header list can see it.
 */
final class BodilessResponseHttpServerTest extends TestCase
{
    private const string ORIGIN = 'https://app.example.com';

    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/bodiless-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        \mkdir(self::$root . '/sessions', 0o777, true);
        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            \$app = \$_SERVER['HTTP_X_TEST_APP'] ?? 'app';
            Polidog\\Relayer\\Router\\AppRouter::create(dirname(__DIR__) . '/' . \$app)->run();
            PHP);

        $route = <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Response;

            return [
                'GET' => static fn (): Response => Response::json(['id' => 3]),
                'DELETE' => static fn (): Response => Response::noContent(),
                'PATCH' => static fn (): Response => Response::noContent()
                    ->withHeader('Content-Type', 'application/merge-patch+json'),
            ];
            PHP;
        self::write('app/todos/route.php', $route);
        self::write('app/cached/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Cache;
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->cache(new Cache(etag: 'v1'));

                return static fn (): Element => new Element('p', [], ['cached']);
            };
            PHP);
        self::write('cors/middleware.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Cors;

            return Cors::middleware(['origins' => ['https://app.example.com']]);
            PHP);
        self::write('cors/todos/route.php', $route);

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        if ('' !== self::$root) {
            self::rmrf(self::$root);
        }
    }

    public function testAutoOptionsOnARouteHasNoContentType(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/todos');

        self::assertSame(204, $status);
        self::assertSame('DELETE, GET, HEAD, OPTIONS, PATCH', $headers['allow'] ?? null);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $body);
    }

    public function testAutoOptionsOnAPageHasNoContentType(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/cached');

        self::assertSame(204, $status);
        self::assertSame('GET, HEAD, OPTIONS, POST', $headers['allow'] ?? null);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $body);
    }

    public function testNoContentFromAHandlerHasNoContentType(): void
    {
        [$status, $headers, $body] = $this->request('DELETE', '/todos');

        self::assertSame(204, $status);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $body);
    }

    public function testAnExplicitContentTypeOnA204IsKept(): void
    {
        [$status, $headers] = $this->request('PATCH', '/todos');

        self::assertSame(204, $status);
        self::assertSame('application/merge-patch+json', $headers['content-type'] ?? null);
    }

    public function testAResponseWithABodyKeepsItsContentType(): void
    {
        [$status, $headers, $body] = $this->request('GET', '/todos');

        self::assertSame(200, $status);
        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        self::assertSame('{"id":3}', $body);
    }

    public function testNotModifiedFromTheHttpCacheHasNoContentType(): void
    {
        [$status, $headers] = $this->request('GET', '/cached');
        self::assertSame(200, $status);
        self::assertSame('"v1"', $headers['etag'] ?? null);

        [$status, $headers, $body] = $this->request('GET', '/cached', ['If-None-Match' => '"v1"']);

        self::assertSame(304, $status);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $body);
    }

    public function testCorsPreflightHasNoContentType(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/todos', [
            'X-Test-App' => 'cors',
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'DELETE',
        ]);

        self::assertSame(204, $status);
        self::assertSame(self::ORIGIN, $headers['access-control-allow-origin'] ?? null);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string}
     */
    private function request(string $method, string $path, array $headers = []): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = \stream_context_create(['http' => [
            'method' => $method,
            'header' => $headerLines,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $response = \file_get_contents(self::$url . $path, false, $context);
        self::assertIsString($response);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $responseHeaders = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $responseHeaders[\strtolower(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $responseHeaders, $response];
    }

    private static function startServer(): void
    {
        // Ask the OS for a free port rather than guessing one.
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $log = self::$root . '/server.log';
        $pipes = [];
        $server = \proc_open(
            [
                \PHP_BINARY,
                '-d', 'xdebug.mode=off',
                '-d', 'session.save_path=' . self::$root . '/sessions',
                '-S', $address,
                '-t', self::$root . '/public',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        if (!\is_resource($server)) {
            throw new RuntimeException('Could not start php -S.');
        }
        self::$server = $server;

        for ($i = 0; $i < 100; ++$i) {
            $socket = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (false !== $socket) {
                \fclose($socket);
                self::$url = 'http://' . $address;

                return;
            }
            \usleep(50_000);
        }

        throw new RuntimeException('php -S did not start: ' . (string) \file_get_contents($log));
    }

    private static function write(string $relative, string $contents): void
    {
        $path = self::$root . '/' . $relative;
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o777, true);
        }
        \file_put_contents($path, $contents);
    }

    private static function rmrf(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
