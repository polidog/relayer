<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\Route;

class RouteTest extends TestCase
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

        $this->assertFalse($route->isDynamic());
        $this->assertSame([], $route->match('/'));
        $this->assertNull($route->match('/about'));
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

        $this->assertFalse($route->isDynamic());
        $this->assertSame([], $route->match('/about'));
        $this->assertNull($route->match('/'));
        $this->assertNull($route->match('/about/extra'));
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

        $this->assertTrue($route->isDynamic());
        $this->assertSame(['slug' => 'hello-world'], $route->match('/blog/hello-world'));
        $this->assertSame(['slug' => '123'], $route->match('/blog/123'));
        $this->assertNull($route->match('/blog'));
        $this->assertNull($route->match('/'));
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

        $this->assertNull($route->match('/blog/hello/extra'));
    }
}
