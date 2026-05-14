<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\Router;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = Router::create(__DIR__ . '/../fixtures/app');
    }

    public function testMatchRootPath(): void
    {
        $match = $this->router->match('/');
        $this->assertNotNull($match);
        $this->assertStringEndsWith('page.php', $match->getPagePath());
    }

    public function testMatchAboutPath(): void
    {
        $match = $this->router->match('/about');
        $this->assertNotNull($match);
        $this->assertStringContains('about', $match->getPagePath());
    }

    public function testMatchDynamicBlogPath(): void
    {
        $match = $this->router->match('/blog/hello-world');
        $this->assertNotNull($match);
        $this->assertSame('hello-world', $match->getParam('slug'));
    }

    public function testNoMatchReturnsNull(): void
    {
        $match = $this->router->match('/nonexistent');
        $this->assertNull($match);
    }

    public function testNormalizesPathWithQueryString(): void
    {
        $match = $this->router->match('/about?foo=bar');
        $this->assertNotNull($match);
    }

    public function testNormalizesTrailingSlash(): void
    {
        $match = $this->router->match('/about/');
        $this->assertNotNull($match);
    }

    public function testGetErrorPagePath(): void
    {
        $errorPath = $this->router->getErrorPagePath();
        $this->assertNotNull($errorPath);
        $this->assertStringEndsWith('error.php', $errorPath);
    }

    public function testGetRoutes(): void
    {
        $routes = $this->router->getRoutes();
        $this->assertCount(4, $routes);
    }

    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }
}
