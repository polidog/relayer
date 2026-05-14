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

    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }
}
