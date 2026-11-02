<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encoded paths through a real `php -S`: what a client's bytes become in
 * `REQUEST_URI`, whether the route is reached, and whether a middleware
 * comparing `$request->path` sees the path that gets routed.
 */
final class PathDecodingHttpServerTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/path-decoding-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            Polidog\\Relayer\\Router\\AppRouter::create(dirname(__DIR__) . '/src/Pages')->run();
            PHP);
        self::write('src/Pages/middleware.php', <<<'PHP'
            <?php
            return function (Polidog\Relayer\Http\Request $req, Closure $next): void {
                if (str_starts_with($req->path, '/admin') || '/exact' === $req->path || str_starts_with($req->decodedPath(), '/管理')) {
                    http_response_code(403);
                    echo 'blocked';

                    return;
                }
                $next($req);
            };
            PHP);

        $page = <<<'PHP'
            <?php
            return static function (Polidog\Relayer\Router\Component\PageContext $ctx) {
                return new Polidog\Rosanna\Runtime\Element('p', [], ['page:' . json_encode($ctx->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            };
            PHP;
        self::write('src/Pages/日本語/page.php', $page);
        self::write('src/Pages/x/[slug]/page.php', $page);
        self::write('src/Pages/admin/page.php', $page);
        self::write('src/Pages/exact/page.php', $page);
        self::write('src/Pages/管理/page.php', $page);
        self::write('src/Pages/api/[id]/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Request;
            use Polidog\Relayer\Http\Response;
            use Polidog\Relayer\Router\Component\PageContext;
            return ['GET' => static fn (PageContext $ctx): Response => Response::json($ctx->params)];
            PHP);

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

    public function testNonAsciiPageIsReached(): void
    {
        [$status, $body] = $this->get('/%E6%97%A5%E6%9C%AC%E8%AA%9E');

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('page:[]', $body);
    }

    public function testParamsAreDecoded(): void
    {
        self::assertStringContainsString('page:{"slug":"日本"}', \html_entity_decode($this->get('/x/%E6%97%A5%E6%9C%AC')[1]));
        self::assertStringContainsString('page:{"slug":"a b"}', \html_entity_decode($this->get('/x/a%20b')[1]));
        self::assertStringContainsString('page:{"slug":"a/b"}', \html_entity_decode($this->get('/x/a%2Fb')[1]));
    }

    public function testApiRouteParamsAreDecoded(): void
    {
        [$status, $body] = $this->get('/api/%E6%97%A5%E6%9C%AC');

        self::assertSame(200, $status, $body);
        self::assertSame('{"id":"日本"}', $body);
    }

    #[DataProvider('provideMiddlewareSeesEverySpellingOfAProtectedPathCases')]
    public function testMiddlewareSeesEverySpellingOfAProtectedPath(string $path): void
    {
        [$status, $body] = $this->get($path);

        self::assertSame(403, $status, $body);
        self::assertSame('blocked', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMiddlewareSeesEverySpellingOfAProtectedPathCases(): iterable
    {
        yield 'plain' => ['/admin'];

        yield 'exact comparison' => ['/exact'];

        yield 'exact comparison, trailing slash' => ['/exact/'];

        yield 'exact comparison, encoded' => ['/%65xact'];

        yield 'encoded first letter' => ['/%61dmin'];

        yield 'all encoded, lowercase hex' => ['/%61%64%6d%69%6e'];

        yield 'non-ASCII' => ['/%E7%AE%A1%E7%90%86'];

        yield 'non-ASCII, lowercase hex' => ['/%e7%ae%a1%e7%90%86'];
    }

    #[DataProvider('provideUnsafePathsAre404Cases')]
    public function testUnsafePathsAre404(string $path): void
    {
        // `/admin%00` starts with the protected prefix, so the middleware may
        // answer first. Either way the page itself must not run.
        [$status, $body] = $this->get($path);

        self::assertContains($status, [403, 404], $body);
        self::assertStringNotContainsString('page:', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsafePathsAre404Cases(): iterable
    {
        yield 'encoded traversal to the protected page' => ['/x/%2e%2e/admin'];

        yield 'traversal param' => ['/x/%2e%2e'];

        yield 'traversal in one param' => ['/x/..%2F..%2Fetc%2Fpasswd'];

        yield 'NUL' => ['/x/a%00b'];

        yield 'NUL after the protected page' => ['/admin%00'];

        yield 'newline after a page' => ['/%E6%97%A5%E6%9C%AC%E8%AA%9E%0A'];

        yield 'invalid UTF-8' => ['/x/%FF'];

        yield 'malformed escape' => ['/x/%zz'];
    }

    public function testDoubleEncodingIsDecodedOnce(): void
    {
        self::assertStringContainsString('page:{"slug":"%2e%2e"}', \html_entity_decode($this->get('/x/%252e%252e')[1]));
        self::assertSame(404, $this->get('/%2561dmin')[0]);
    }

    /**
     * @return array{int, string}
     */
    private function get(string $path): array
    {
        $context = \stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $body = \file_get_contents(self::$url . $path, false, $context);
        self::assertIsString($body);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    private static function startServer(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $pipes = [];
        $server = \proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, self::$root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::$root . '/server.log', 'a'], 2 => ['file', self::$root . '/server.log', 'a']],
            $pipes,
            self::$root . '/public',
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

        throw new RuntimeException('php -S did not start: ' . (string) \file_get_contents(self::$root . '/server.log'));
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
