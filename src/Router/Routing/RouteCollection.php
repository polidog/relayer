<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Route>
 */
final class RouteCollection implements IteratorAggregate, Countable
{
    /** @var array<Route> */
    private array $routes = [];

    private bool $sorted = false;

    public function add(Route $route): void
    {
        $this->routes[] = $route;
        $this->sorted = false;
    }

    /**
     * @return array<Route>
     */
    public function all(): array
    {
        $this->sort();
        return $this->routes;
    }

    public function match(string $path): ?RouteMatch
    {
        $this->sort();

        foreach ($this->routes as $route) {
            $params = $route->match($path);
            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * @return Traversable<int, Route>
     */
    public function getIterator(): Traversable
    {
        $this->sort();
        return new ArrayIterator($this->routes);
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        usort($this->routes, function (Route $a, Route $b): int {
            $aDynamic = $a->isDynamic() ? 1 : 0;
            $bDynamic = $b->isDynamic() ? 1 : 0;

            if ($aDynamic !== $bDynamic) {
                return $aDynamic - $bDynamic;
            }

            if (!$a->isDynamic()) {
                return $b->totalSegments - $a->totalSegments;
            }

            if ($a->staticSegments !== $b->staticSegments) {
                return $b->staticSegments - $a->staticSegments;
            }

            return $b->totalSegments - $a->totalSegments;
        });

        $this->sorted = true;
    }
}
