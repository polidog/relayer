<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

final class RouteMatch
{
    /**
     * @param Route $route The matched route
     * @param array<string, string> $params Extracted parameters from dynamic segments
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $params,
    ) {
    }

    public function getParam(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getPagePath(): string
    {
        return $this->route->pagePath;
    }

    /**
     * @return array<string>
     */
    public function getLayoutPaths(): array
    {
        return $this->route->layoutPaths;
    }
}
