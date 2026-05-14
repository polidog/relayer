<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;

class PageScannerTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../fixtures/app';
    }

    public function testScanFindsAllPages(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        // Should find: /, /about, /blog/[slug], /form
        $this->assertCount(4, $collection);
    }

    public function testScanCreatesCorrectPatterns(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        $patterns = [];
        foreach ($collection as $route) {
            $patterns[] = $route->pattern;
        }

        sort($patterns);

        $this->assertContains('/', $patterns);
        $this->assertContains('/about', $patterns);
        $this->assertContains('/blog/[slug]', $patterns);
        $this->assertContains('/form', $patterns);
    }

    public function testScanDetectsDynamicSegments(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        $dynamicRoutes = [];
        foreach ($collection as $route) {
            if ($route->isDynamic()) {
                $dynamicRoutes[] = $route;
            }
        }

        $this->assertCount(1, $dynamicRoutes);
        $this->assertSame(['slug'], $dynamicRoutes[0]->paramNames);
    }

    public function testScanFindsLayouts(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        foreach ($collection as $route) {
            if ($route->pattern === '/') {
                // Root page should have root layout
                $this->assertNotEmpty($route->layoutPaths);
                $this->assertStringEndsWith('layout.php', $route->layoutPaths[0]);
            }
        }
    }

    public function testScanFindsErrorPage(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $errorPath = $scanner->getErrorPagePath();

        $this->assertNotNull($errorPath);
        $this->assertStringEndsWith('error.php', $errorPath);
    }

    public function testScanNoErrorPage(): void
    {
        // Use about subdirectory which has no error.php
        $scanner = new PageScanner($this->fixturesDir . '/about');
        $errorPath = $scanner->getErrorPagePath();

        $this->assertNull($errorPath);
    }

    public function testScanThrowsForNonexistentDirectory(): void
    {
        $scanner = new PageScanner('/nonexistent/directory');

        $this->expectException(\RuntimeException::class);
        $scanner->scan();
    }

    public function testDynamicRouteRegex(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        foreach ($collection as $route) {
            if ($route->pattern === '/blog/[slug]') {
                $params = $route->match('/blog/hello-world');
                $this->assertNotNull($params);
                $this->assertSame('hello-world', $params['slug']);

                $this->assertNull($route->match('/blog'));
                $this->assertNull($route->match('/about'));
            }
        }
    }
}
