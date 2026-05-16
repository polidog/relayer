<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Route;
use RuntimeException;

final class ApiRouteScannerTest extends TestCase
{
    private string $apiAppDir;

    protected function setUp(): void
    {
        $this->apiAppDir = __DIR__ . '/../fixtures/api-app';
    }

    public function testScanDiscoversPagesAndRoutesTogether(): void
    {
        $collection = (new PageScanner($this->apiAppDir))->scan();

        // / (page), /ping (route), /echo/[id] (route)
        self::assertCount(3, $collection);

        $byPattern = [];
        foreach ($collection as $route) {
            $byPattern[$route->pattern] = $route;
        }

        self::assertArrayHasKey('/', $byPattern);
        self::assertArrayHasKey('/ping', $byPattern);
        self::assertArrayHasKey('/echo/[id]', $byPattern);
    }

    public function testPageRouteIsNotApiAndApiRouteIs(): void
    {
        $collection = (new PageScanner($this->apiAppDir))->scan();

        foreach ($collection as $route) {
            match ($route->pattern) {
                '/' => self::assertFalse($route->isApi, 'page.php route must not be API'),
                '/ping', '/echo/[id]' => self::assertTrue($route->isApi, 'route.php must be API'),
                default => self::fail("unexpected pattern {$route->pattern}"),
            };
        }
    }

    public function testApiRoutesCarryNoLayouts(): void
    {
        $collection = (new PageScanner($this->apiAppDir))->scan();

        foreach ($collection as $route) {
            if ($route->isApi) {
                self::assertSame([], $route->layoutPaths);
                self::assertStringEndsWith('route.php', $route->pagePath);
            }
        }
    }

    public function testDynamicApiRouteCapturesParam(): void
    {
        $collection = (new PageScanner($this->apiAppDir))->scan();

        $dynamic = null;
        foreach ($collection as $route) {
            if ('/echo/[id]' === $route->pattern) {
                $dynamic = $route;
            }
        }

        self::assertInstanceOf(Route::class, $dynamic);
        self::assertTrue($dynamic->isApi);
        self::assertTrue($dynamic->isDynamic());
        self::assertSame(['id' => '42'], $dynamic->match('/echo/42'));
    }

    public function testPageAndRouteInSameDirectoryIsRejected(): void
    {
        $scanner = new PageScanner(__DIR__ . '/../fixtures/api-conflict');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Both a page file and route.php exist');
        $scanner->scan();
    }
}
