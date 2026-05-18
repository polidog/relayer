<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = Router::create(__DIR__ . '/../fixtures/app');
    }

    public function testMatchRootPath(): void
    {
        $match = $this->router->match('/');
        self::assertNotNull($match);
        self::assertStringEndsWith('page.php', $match->getPagePath());
    }

    public function testMatchAboutPath(): void
    {
        $match = $this->router->match('/about');
        self::assertNotNull($match);
        $this->assertStringContains('about', $match->getPagePath());
    }

    public function testMatchDynamicBlogPath(): void
    {
        $match = $this->router->match('/blog/hello-world');
        self::assertNotNull($match);
        self::assertSame('hello-world', $match->getParam('slug'));
    }

    public function testNoMatchReturnsNull(): void
    {
        $match = $this->router->match('/nonexistent');
        self::assertNull($match);
    }

    public function testNormalizesPathWithQueryString(): void
    {
        $match = $this->router->match('/about?foo=bar');
        self::assertNotNull($match);
    }

    public function testNormalizesTrailingSlash(): void
    {
        $match = $this->router->match('/about/');
        self::assertNotNull($match);
    }

    public function testGetErrorPagePath(): void
    {
        $errorPath = $this->router->getErrorPagePath();
        self::assertNotNull($errorPath);
        self::assertStringEndsWith('error.php', $errorPath);
    }

    public function testGetRoutes(): void
    {
        $routes = $this->router->getRoutes();
        self::assertCount(4, $routes);
    }

    public function testLoadsFromCompiledFileWhenPresentAndSkipsScanning(): void
    {
        // A compiled artifact that deliberately disagrees with the
        // filesystem: it advertises /from-compiled and omits /about. If
        // the router honours the file it must match the former and miss
        // the latter — proof the filesystem scan was bypassed.
        $file = \sys_get_temp_dir() . '/router-compiled-' . \uniqid() . '.php';
        \file_put_contents($file, <<<'PHP'
            <?php

            return [
                [
                    'pattern' => '/from-compiled',
                    'regex' => '#^/from\-compiled$#',
                    'pagePath' => 'page.php',
                    'layoutPaths' => [],
                    'paramNames' => [],
                    'staticSegments' => 1,
                    'totalSegments' => 1,
                    'isApi' => false,
                ],
            ];
            PHP);

        try {
            $router = Router::create(__DIR__ . '/../fixtures/app', $file);

            self::assertNotNull($router->match('/from-compiled'));
            self::assertNull($router->match('/about'));
            self::assertCount(1, $router->getRoutes());
        } finally {
            @\unlink($file);
        }
    }

    public function testFallsBackToScanWhenCompiledFileIsAbsent(): void
    {
        $router = Router::create(
            __DIR__ . '/../fixtures/app',
            \sys_get_temp_dir() . '/does-not-exist-' . \uniqid() . '.php',
        );

        self::assertNotNull($router->match('/about'));
        self::assertCount(4, $router->getRoutes());
    }

    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }
}
