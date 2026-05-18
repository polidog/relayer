<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Router;
use RuntimeException;

final class RouteGroupScannerTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../fixtures/group-app';
    }

    public function testRouteGroupsAreStrippedFromUrls(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        $patterns = [];
        foreach ($collection as $route) {
            $patterns[] = $route->pattern;
        }
        \sort($patterns);

        // (marketing)/ → /, (marketing)/about/ → /about, (shop)/cart/ → /cart
        self::assertSame(['/', '/about', '/cart'], $patterns);
    }

    public function testGroupOnlyPathMapsToRoot(): void
    {
        $match = Router::create($this->fixturesDir)->match('/');

        self::assertNotNull($match);
        self::assertStringContainsString(
            '(marketing)/page.php',
            $match->getPagePath(),
            'The root URL must resolve to the page inside the (marketing) group.',
        );
    }

    public function testPrivateFoldersAreNotRouted(): void
    {
        $router = Router::create($this->fixturesDir);

        // `_internal/secret/page.php` and `(marketing)/_draft/page.php`
        // live under `_private` folders and must not be reachable.
        self::assertNull($router->match('/internal/secret'));
        self::assertNull($router->match('/secret'));
        self::assertNull($router->match('/draft'));
        self::assertCount(3, $router->getRoutes());
    }

    public function testGroupLayoutStacksUnderRootLayout(): void
    {
        $scanner = new PageScanner($this->fixturesDir);

        foreach ($scanner->scan() as $route) {
            if ('/about' !== $route->pattern) {
                continue;
            }

            self::assertCount(2, $route->layoutPaths);
            self::assertStringEndsWith('group-app/layout.php', $route->layoutPaths[0]);
            self::assertStringEndsWith(
                '(marketing)/layout.php',
                $route->layoutPaths[1],
                'A route group may carry its own layout, nested under the root layout.',
            );

            return;
        }

        self::fail('Expected an /about route.');
    }

    public function testCollidingRouteGroupsAreRejected(): void
    {
        $tmp = \sys_get_temp_dir() . '/route-group-' . \uniqid();
        \mkdir($tmp . '/(a)/about', 0o777, true);
        \mkdir($tmp . '/(b)/about', 0o777, true);

        try {
            \file_put_contents($tmp . '/(a)/about/page.php', "<?php\nreturn fn() => null;\n");
            \file_put_contents($tmp . '/(b)/about/page.php', "<?php\nreturn fn() => null;\n");

            $scanner = new PageScanner($tmp);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Route pattern "/about" is produced by two files');
            $scanner->scan();
        } finally {
            @\unlink($tmp . '/(a)/about/page.php');
            @\unlink($tmp . '/(b)/about/page.php');
            @\rmdir($tmp . '/(a)/about');
            @\rmdir($tmp . '/(b)/about');
            @\rmdir($tmp . '/(a)');
            @\rmdir($tmp . '/(b)');
            @\rmdir($tmp);
        }
    }
}
