<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;
use RuntimeException;

final class PageScannerTest extends TestCase
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
        self::assertCount(4, $collection);
    }

    public function testScanCreatesCorrectPatterns(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        $patterns = [];
        foreach ($collection as $route) {
            $patterns[] = $route->pattern;
        }

        \sort($patterns);

        self::assertContains('/', $patterns);
        self::assertContains('/about', $patterns);
        self::assertContains('/blog/[slug]', $patterns);
        self::assertContains('/form', $patterns);
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

        self::assertCount(1, $dynamicRoutes);
        self::assertSame(['slug'], $dynamicRoutes[0]->paramNames);
    }

    public function testScanFindsLayouts(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        foreach ($collection as $route) {
            if ('/' === $route->pattern) {
                // Root page should have root layout
                self::assertNotEmpty($route->layoutPaths);
                self::assertStringEndsWith('layout.php', $route->layoutPaths[0]);
            }
        }
    }

    public function testScanFindsErrorPage(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $errorPath = $scanner->getErrorPagePath();

        self::assertNotNull($errorPath);
        self::assertStringEndsWith('error.php', $errorPath);
    }

    public function testScanNoErrorPage(): void
    {
        // Use about subdirectory which has no error.php
        $scanner = new PageScanner($this->fixturesDir . '/about');
        $errorPath = $scanner->getErrorPagePath();

        self::assertNull($errorPath);
    }

    public function testScanThrowsForNonexistentDirectory(): void
    {
        $scanner = new PageScanner('/nonexistent/directory');

        $this->expectException(RuntimeException::class);
        $scanner->scan();
    }

    public function testDynamicRouteRegex(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        foreach ($collection as $route) {
            if ('/blog/[slug]' === $route->pattern) {
                $params = $route->match('/blog/hello-world');
                self::assertNotNull($params);
                self::assertSame('hello-world', $params['slug']);

                self::assertNull($route->match('/blog'));
                self::assertNull($route->match('/about'));
            }
        }
    }
}
