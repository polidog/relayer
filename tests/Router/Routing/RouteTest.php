<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\Route;

final class RouteTest extends TestCase
{
    public function testStaticRouteMatches(): void
    {
        $route = new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        );

        self::assertFalse($route->isDynamic());
        self::assertSame([], $route->match('/'));
        self::assertNull($route->match('/about'));
    }

    public function testStaticMultiSegmentRouteMatches(): void
    {
        $route = new Route(
            pattern: '/about',
            regex: '#^/about$#',
            pagePath: '/app/about/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 1,
            totalSegments: 1,
        );

        self::assertFalse($route->isDynamic());
        self::assertSame([], $route->match('/about'));
        self::assertNull($route->match('/'));
        self::assertNull($route->match('/about/extra'));
    }

    public function testDynamicRouteMatches(): void
    {
        $route = new Route(
            pattern: '/blog/[slug]',
            regex: '#^/blog/(?P<slug>[^/]+)$#',
            pagePath: '/app/blog/[slug]/page.php',
            layoutPaths: [],
            paramNames: ['slug'],
            staticSegments: 1,
            totalSegments: 2,
        );

        self::assertTrue($route->isDynamic());
        self::assertSame(['slug' => 'hello-world'], $route->match('/blog/hello-world'));
        self::assertSame(['slug' => '123'], $route->match('/blog/123'));
        self::assertNull($route->match('/blog'));
        self::assertNull($route->match('/'));
    }

    public function testDynamicRouteDoesNotMatchExtraSegments(): void
    {
        $route = new Route(
            pattern: '/blog/[slug]',
            regex: '#^/blog/(?P<slug>[^/]+)$#',
            pagePath: '/app/blog/[slug]/page.php',
            layoutPaths: [],
            paramNames: ['slug'],
            staticSegments: 1,
            totalSegments: 2,
        );

        self::assertNull($route->match('/blog/hello/extra'));
    }
}
