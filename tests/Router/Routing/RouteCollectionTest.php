<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteCollection;

class RouteCollectionTest extends TestCase
{
    public function testMatchStaticRoute(): void
    {
        $collection = new RouteCollection();

        $collection->add(new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        ));

        $collection->add(new Route(
            pattern: '/about',
            regex: '#^/about$#',
            pagePath: '/app/about/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 1,
            totalSegments: 1,
        ));

        $match = $collection->match('/about');
        $this->assertNotNull($match);
        $this->assertSame('/about', $match->route->pattern);
    }

    public function testMatchRootRoute(): void
    {
        $collection = new RouteCollection();

        $collection->add(new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        ));

        $match = $collection->match('/');
        $this->assertNotNull($match);
        $this->assertSame('/', $match->route->pattern);
    }

    public function testMatchDynamicRoute(): void
    {
        $collection = new RouteCollection();

        $collection->add(new Route(
            pattern: '/blog/[slug]',
            regex: '#^/blog/(?P<slug>[^/]+)$#',
            pagePath: '/app/blog/[slug]/page.php',
            layoutPaths: [],
            paramNames: ['slug'],
            staticSegments: 1,
            totalSegments: 2,
        ));

        $match = $collection->match('/blog/my-post');
        $this->assertNotNull($match);
        $this->assertSame('my-post', $match->getParam('slug'));
    }

    public function testNoMatch(): void
    {
        $collection = new RouteCollection();

        $collection->add(new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        ));

        $this->assertNull($collection->match('/nonexistent'));
    }

    public function testStaticRoutePriorityOverDynamic(): void
    {
        $collection = new RouteCollection();

        // Add dynamic first
        $collection->add(new Route(
            pattern: '/blog/[slug]',
            regex: '#^/blog/(?P<slug>[^/]+)$#',
            pagePath: '/app/blog/[slug]/page.php',
            layoutPaths: [],
            paramNames: ['slug'],
            staticSegments: 1,
            totalSegments: 2,
        ));

        // Add static second
        $collection->add(new Route(
            pattern: '/blog/featured',
            regex: '#^/blog/featured$#',
            pagePath: '/app/blog/featured/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 2,
            totalSegments: 2,
        ));

        // Static should match first
        $match = $collection->match('/blog/featured');
        $this->assertNotNull($match);
        $this->assertSame('/blog/featured', $match->route->pattern);
        $this->assertSame([], $match->getParams());
    }

    public function testCount(): void
    {
        $collection = new RouteCollection();
        $this->assertCount(0, $collection);

        $collection->add(new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        ));

        $this->assertCount(1, $collection);
    }

    public function testIterable(): void
    {
        $collection = new RouteCollection();

        $collection->add(new Route(
            pattern: '/',
            regex: '#^/$#',
            pagePath: '/app/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 0,
            totalSegments: 0,
        ));

        $collection->add(new Route(
            pattern: '/about',
            regex: '#^/about$#',
            pagePath: '/app/about/page.php',
            layoutPaths: [],
            paramNames: [],
            staticSegments: 1,
            totalSegments: 1,
        ));

        $routes = [];
        foreach ($collection as $route) {
            $routes[] = $route->pattern;
        }

        $this->assertCount(2, $routes);
    }
}
