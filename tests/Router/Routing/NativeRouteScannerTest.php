<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteKind;
use RuntimeException;

final class NativeRouteScannerTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = __DIR__ . '/../fixtures/native-app';
    }

    public function testRouteGoIsDiscoveredAsANativeRoute(): void
    {
        $collection = (new PageScanner($this->appDir))->scan();

        // / (page), /api/hello (route.go), /api/users/[id] (route.go)
        self::assertCount(3, $collection);

        $byPattern = [];
        foreach ($collection as $route) {
            $byPattern[$route->pattern] = $route;
        }

        self::assertSame(RouteKind::Page, $byPattern['/']->kind);
        self::assertFalse($byPattern['/']->isApi);

        $hello = $byPattern['/api/hello'];
        self::assertSame(RouteKind::Native, $hello->kind);
        self::assertFalse($hello->isApi, 'a native route is not a PHP API route');
        self::assertStringEndsWith('/api/hello/route.go', $hello->pagePath);
        self::assertSame([], $hello->layoutPaths, 'endpoints carry no layouts');
    }

    public function testDynamicNativeRouteCapturesParams(): void
    {
        $collection = (new PageScanner($this->appDir))->scan();

        $dynamic = null;
        foreach ($collection as $route) {
            if ('/api/users/[id]' === $route->pattern) {
                $dynamic = $route;
            }
        }

        self::assertInstanceOf(Route::class, $dynamic);
        self::assertSame(RouteKind::Native, $dynamic->kind);
        self::assertTrue($dynamic->isDynamic());
        self::assertSame(['id' => '42'], $dynamic->match('/api/users/42'));
    }

    public function testRoutePhpAndRouteGoInTheSameDirectoryIsRejected(): void
    {
        $scanner = new PageScanner(__DIR__ . '/../fixtures/native-conflict');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Both route.php and route.go exist');
        $scanner->scan();
    }

    public function testPageAndRouteGoInTheSameDirectoryIsRejected(): void
    {
        $scanner = new PageScanner(__DIR__ . '/../fixtures/native-page-conflict');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Both a page file and route.go exist');
        $scanner->scan();
    }

    public function testPrivateFoldersHideRouteGoToo(): void
    {
        $dir = \sys_get_temp_dir() . '/native-private-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/_internal/tool', 0o777, true);
        \mkdir($dir . '/api', 0o777, true);
        \file_put_contents($dir . '/_internal/tool/route.go', "package main\n");
        \file_put_contents($dir . '/api/route.go', "package main\n");

        try {
            $patterns = [];
            foreach ((new PageScanner($dir))->scan() as $route) {
                $patterns[] = $route->pattern;
            }
            self::assertSame(['/api'], $patterns);
        } finally {
            @\unlink($dir . '/_internal/tool/route.go');
            @\unlink($dir . '/api/route.go');
            @\rmdir($dir . '/_internal/tool');
            @\rmdir($dir . '/_internal');
            @\rmdir($dir . '/api');
            @\rmdir($dir);
        }
    }
}
