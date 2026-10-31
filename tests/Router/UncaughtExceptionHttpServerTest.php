<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Uncaught exceptions through `Relayer::boot()` behind a real `php -S`,
 * asserted over real HTTP: only a web SAPI shows the emitted headers and what
 * happens once they are on the wire.
 *
 * `display_errors` is deliberately ON. It proves the prod responses are clean
 * because the framework answered, not because PHP was told to stay quiet.
 */
final class UncaughtExceptionHttpServerTest extends TestCase
{
    private const SECRET = 'db-password-hunter2';

    /** @var array<string, array{root: string, url: string, server: resource}> */
    private static array $apps = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$apps as $app) {
            \proc_terminate($app['server']);
            \proc_close($app['server']);
            self::rmrf($app['root']);
        }
        self::$apps = [];
    }

    public function testProdPageAnswersTheErrorPageWithoutCacheHeaders(): void
    {
        [$status, $headers, $body] = $this->request('prod', 'GET', '/cached-boom');

        self::assertSame(500, $status);
        self::assertStringStartsWith('text/html', $headers['content-type'] ?? '');
        self::assertStringContainsString('custom-error-page:500:Internal Server Error', $body);
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('etag', $headers);
        self::assertArrayNotHasKey('last-modified', $headers);
        $this->assertNothingLeaked($body);
    }

    public function testProdRouteAnswersJson(): void
    {
        [$status, $headers, $body] = $this->request('prod', 'GET', '/api-boom');

        self::assertSame(500, $status);
        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        self::assertSame('{"error":"Internal Server Error"}', $body);
    }

    public function testProdRouteReturningSomethingElseAnswersJson(): void
    {
        [$status, $headers, $body] = $this->request('prod', 'GET', '/api-raw');

        self::assertSame(500, $status);
        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        self::assertSame('{"error":"Internal Server Error"}', $body);
    }

    public function testProdWritesTheExceptionToTheLogOnly(): void
    {
        [, , $body] = $this->request('prod', 'GET', '/api-boom?marker=logged');

        $this->assertNothingLeaked($body);
        self::assertStringContainsString(self::SECRET, (string) \file_get_contents(self::$apps['prod']['root'] . '/server.log'));
    }

    public function testPartialOutputBeforeTheFailureIsReplaced(): void
    {
        [$status, $headers, $body] = $this->request('prod', 'GET', '/echo-boom');

        self::assertSame(500, $status);
        self::assertStringStartsWith('text/html', $headers['content-type'] ?? '');
        self::assertStringNotContainsString('half-rendered', $body);
        $this->assertNothingLeaked($body);
    }

    public function testHeadersAlreadySentLeaveTheStatusAndAddNoDetails(): void
    {
        [$status, , $body] = $this->request('prod', 'GET', '/flush-boom');

        // The 200 is on the wire: nothing can change it. It must still not
        // grow a second document or the exception's details.
        self::assertSame(200, $status);
        self::assertSame('flushed', $body);

        // The server keeps answering afterwards.
        [$next] = $this->request('prod', 'GET', '/ok');
        self::assertSame(200, $next);
    }

    public function testHeadersAlreadySentInDevAppendTheTrace(): void
    {
        [$status, , $body] = $this->request('dev', 'GET', '/flush-boom');

        self::assertSame(200, $status);
        self::assertStringStartsWith('flushed', $body);
        self::assertStringContainsString(self::SECRET, $body);
    }

    public function testDevPageShowsTheStackTrace(): void
    {
        [$status, $headers, $body] = $this->request('dev', 'GET', '/cached-boom');

        self::assertSame(500, $status);
        self::assertStringStartsWith('text/html', $headers['content-type'] ?? '');
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertStringContainsString(self::SECRET, $body);
        self::assertStringContainsString('#0 ', $body);
        self::assertArrayHasKey('x-debug-token', $headers);
    }

    public function testDevRouteCarriesTheTraceInJson(): void
    {
        [$status, $headers, $body] = $this->request('dev', 'GET', '/api-boom');

        self::assertSame(500, $status);
        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        $decoded = \json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('Internal Server Error', $decoded['error'] ?? null);
        self::assertIsArray($decoded['exception'] ?? null);
        self::assertSame(self::SECRET, $decoded['exception']['message'] ?? null);
    }

    public function testDevProfileRecordsTheFailure(): void
    {
        [, $headers] = $this->request('dev', 'GET', '/cached-boom');
        $token = $headers['x-debug-token'] ?? '';
        self::assertNotSame('', $token);

        [$status, , $body] = $this->request('dev', 'GET', '/_profiler/' . $token);

        self::assertSame(200, $status);
        self::assertStringContainsString('exception', $body);
        self::assertStringContainsString(self::SECRET, $body);
        self::assertStringContainsString('500', $body);
    }

    private function assertNothingLeaked(string $body): void
    {
        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('Stack trace', $body);
        self::assertStringNotContainsString('.php', $body);
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function request(string $env, string $method, string $path): array
    {
        $app = self::$apps[$env] ?? self::startApp($env);

        $context = \stream_context_create(['http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $response = \file_get_contents($app['url'] . $path, false, $context);
        self::assertIsString($response);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $headers[\strtolower(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $headers, $response];
    }

    /**
     * @return array{root: string, url: string, server: resource}
     */
    private static function startApp(string $env): array
    {
        $root = \sys_get_temp_dir() . '/uncaught-http-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $secret = self::SECRET;

        self::write($root, 'public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            Polidog\\Relayer\\Relayer::boot(dirname(__DIR__))->run();
            PHP);
        self::write($root, 'src/Pages/ok/page.php', <<<'PHP'
            <?php
            return static fn () => static fn () => new Polidog\UsePhp\Runtime\Element('p', [], ['fine']);
            PHP);
        self::write($root, 'src/Pages/cached-boom/page.php', <<<PHP
            <?php
            use Polidog\\Relayer\\Http\\Cache;
            use Polidog\\Relayer\\Router\\Component\\PageContext;
            return static function (PageContext \$ctx) {
                \$ctx->cache(new Cache(maxAge: 3600, etag: 'v1', public: true));

                return static function () {
                    throw new RuntimeException('{$secret}');
                };
            };
            PHP);
        self::write($root, 'src/Pages/echo-boom/page.php', <<<PHP
            <?php
            return static function () {
                echo 'half-rendered';

                throw new RuntimeException('{$secret}');
            };
            PHP);
        self::write($root, 'src/Pages/flush-boom/page.php', <<<PHP
            <?php
            return static function () {
                echo 'flushed';
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();

                throw new RuntimeException('{$secret}');
            };
            PHP);
        self::write($root, 'src/Pages/api-boom/route.php', <<<PHP
            <?php
            return ['GET' => static function () {
                throw new RuntimeException('{$secret}');
            }];
            PHP);
        self::write($root, 'src/Pages/api-raw/route.php', <<<'PHP'
            <?php
            return ['GET' => static fn () => ['not' => 'a response']];
            PHP);
        self::write($root, 'src/Pages/error.php', <<<'PHP'
            <?php
            namespace App\Pages;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\UsePhp\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['custom-error-page:' . $this->getStatusCode() . ':' . $this->getMessage()]);
                }
            }
            PHP);

        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $environment = \getenv();
        $environment['APP_ENV'] = $env;
        unset($environment['LOG_FILE']);

        $log = $root . '/server.log';
        $pipes = [];
        $server = \proc_open(
            [
                \PHP_BINARY,
                '-d', 'xdebug.mode=off',
                '-d', 'display_errors=1',
                '-d', 'output_buffering=0',
                '-S', $address,
                '-t', $root . '/public',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $environment,
        );
        if (!\is_resource($server)) {
            throw new RuntimeException('Could not start php -S.');
        }

        for ($i = 0; $i < 100; ++$i) {
            $socket = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (false !== $socket) {
                \fclose($socket);

                return self::$apps[$env] = ['root' => $root, 'url' => 'http://' . $address, 'server' => $server];
            }
            \usleep(50_000);
        }

        \proc_terminate($server);

        throw new RuntimeException('php -S did not start: ' . \file_get_contents($log));
    }

    private static function write(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
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
