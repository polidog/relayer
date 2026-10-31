<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\CompiledRoutes;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Router;

final class RouteDecodingTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = \sys_get_temp_dir() . '/route-decoding-' . \bin2hex(\random_bytes(6));

        foreach ([
            'page.php',
            '日本語/page.php',
            'my page/page.php',
            '100%/page.php',
            '(marketing)/料金/page.php',
            'admin/page.php',
            'docs/api/page.php',
            'x/[slug]/page.php',
            'api/記事/[id]/route.php',
            'go/[slug]/route.go',
        ] as $file) {
            $path = $this->appDir . '/' . $file;
            if (!\is_dir(\dirname($path))) {
                \mkdir(\dirname($path), 0o777, true);
            }
            \file_put_contents($path, '<?php');
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->appDir);
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('reachable')]
    public function testDecodedPathsReachTheirRoute(string $path, string $pattern, array $params): void
    {
        $match = Router::create($this->appDir)->match($path);

        self::assertNotNull($match, $path);
        self::assertSame($pattern, $match->route->pattern);
        self::assertSame($params, $match->params);
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('reachable')]
    public function testCompiledRoutesDecodeTheSameWay(string $path, string $pattern, array $params): void
    {
        $file = $this->appDir . '/routes.compiled.php';
        \file_put_contents($file, CompiledRoutes::export((new PageScanner($this->appDir))->scan(), $this->appDir));

        $match = Router::create($this->appDir, $file)->match($path);

        self::assertNotNull($match, $path);
        self::assertSame($pattern, $match->route->pattern);
        self::assertSame($params, $match->params);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function reachable(): iterable
    {
        yield 'static non-ASCII' => ['/%E6%97%A5%E6%9C%AC%E8%AA%9E', '/日本語', []];

        yield 'static non-ASCII, lowercase hex' => ['/%e6%97%a5%e6%9c%ac%e8%aa%9e', '/日本語', []];

        yield 'static non-ASCII, raw' => ['/日本語', '/日本語', []];

        yield 'static with a space' => ['/my%20page', '/my page', []];

        yield 'static with a literal percent' => ['/100%25', '/100%', []];

        yield 'route group' => ['/%E6%96%99%E9%87%91', '/料金', []];

        yield 'encoded ASCII' => ['/%61dmin', '/admin', []];

        yield 'dynamic non-ASCII param' => ['/x/%E6%97%A5%E6%9C%AC', '/x/[slug]', ['slug' => '日本']];

        yield 'dynamic param with a space' => ['/x/a%20b', '/x/[slug]', ['slug' => 'a b']];

        yield 'plus stays a plus' => ['/x/a+b', '/x/[slug]', ['slug' => 'a+b']];

        yield 'encoded slash stays in one param' => ['/x/a%2Fb', '/x/[slug]', ['slug' => 'a/b']];

        yield 'one decode only' => ['/x/%252e%252e', '/x/[slug]', ['slug' => '%2e%2e']];

        yield 'one decode only, encoded slash' => ['/x/a%252Fb', '/x/[slug]', ['slug' => 'a%2Fb']];

        yield 'API route' => ['/api/%E8%A8%98%E4%BA%8B/a%20b', '/api/記事/[id]', ['id' => 'a b']];

        yield 'native route' => ['/go/%E6%97%A5', '/go/[slug]', ['slug' => '日']];

        yield 'a dot in a param' => ['/x/a.b', '/x/[slug]', ['slug' => 'a.b']];
    }

    #[DataProvider('provideUnsafeOrWrongPathsMatchNothingCases')]
    public function testUnsafeOrWrongPathsMatchNothing(string $path): void
    {
        self::assertNull(Router::create($this->appDir)->match($path), $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsafeOrWrongPathsMatchNothingCases(): iterable
    {
        yield 'encoded slash does not split a static route' => ['/docs%2Fapi'];

        yield 'encoded slash does not split a dynamic route' => ['/x%2Fa'];

        yield 'dot-dot param' => ['/x/..'];

        yield 'encoded dot-dot param' => ['/x/%2e%2e'];

        yield 'traversal in one param' => ['/x/..%2F..%2Fetc%2Fpasswd'];

        yield 'backslash traversal in one param' => ['/x/..%5C..%5Cwin'];

        yield 'traversal back into a static route' => ['/x/%2e%2e/admin'];

        yield 'NUL param' => ['/x/a%00b'];

        yield 'NUL after a static route' => ['/admin%00'];

        yield 'newline after a static route' => ['/admin%0A'];

        yield 'newline after a param' => ['/x/a%0A'];

        yield 'invalid UTF-8 param' => ['/x/%FF'];

        yield 'overlong UTF-8 param' => ['/x/%C0%AE%C0%AE'];

        yield 'malformed escape param' => ['/x/%zz'];

        yield 'a literal percent needs encoding' => ['/100%'];

        yield 'double-encoded ASCII is not the route' => ['/%2561dmin'];
    }

    private function rmrf(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
