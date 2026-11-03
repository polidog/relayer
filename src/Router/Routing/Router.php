<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

final class Router implements RouterInterface
{
    private ?RouteCollection $routes = null;
    private ?string $errorPagePath = null;
    private bool $errorPagePathLoaded = false;

    /**
     * @param null|string $compiledRoutesFile when set and the file exists,
     *                                        routes are loaded from this
     *                                        precompiled artifact instead
     *                                        of scanning the filesystem.
     *                                        Absent file → live scan, so
     *                                        dev (which never compiles)
     *                                        always reflects the tree.
     */
    public function __construct(
        private readonly PageScanner $scanner,
        private readonly ?string $compiledRoutesFile = null,
    ) {}

    public static function create(string $appDirectory, ?string $compiledRoutesFile = null): self
    {
        return new self(new PageScanner($appDirectory), $compiledRoutesFile);
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
        if (null === $this->routes) {
            $this->routes = null !== $this->compiledRoutesFile && \is_file($this->compiledRoutesFile)
                ? CompiledRoutes::load($this->compiledRoutesFile, $this->scanner->appDirectory())
                : $this->scanner->scan();
        }

        return $this->routes;
    }

    private function normalizePath(string $path): string
    {
        // A leading `//` stays as it is: the route match refuses it, where
        // collapsing it would make `//admin` reach `/admin`.
        if (\str_starts_with($path, '//')) {
            return $path;
        }

        $path = '/' . \trim(\explode('?', $path, 2)[0], '/');

        return '/' !== $path ? \rtrim($path, '/') : $path;
    }
}
