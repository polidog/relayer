<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

final class Router implements RouterInterface
{
    private ?RouteCollection $routes = null;
    private ?string $errorPagePath = null;
    private bool $errorPagePathLoaded = false;

    public function __construct(
        private readonly PageScanner $scanner,
    ) {
    }

    public static function create(string $appDirectory): self
    {
        return new self(new PageScanner($appDirectory));
    }

    public function match(string $path): ?RouteMatch
    {
        $routes = $this->getRoutes();
        $path = $this->normalizePath($path);

        return $routes->match($path);
    }

    public function getErrorPagePath(): ?string
    {
        if (!$this->errorPagePathLoaded) {
            $this->errorPagePath = $this->scanner->getErrorPagePath();
            $this->errorPagePathLoaded = true;
        }

        return $this->errorPagePath;
    }

    public function getRoutes(): RouteCollection
    {
        if ($this->routes === null) {
            $this->routes = $this->scanner->scan();
        }

        return $this->routes;
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?? '/';
        $path = '/' . trim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }
}
