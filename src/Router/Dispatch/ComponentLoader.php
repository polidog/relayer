<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\TraceableAppRouter;
use Polidog\UsePhp\Component\ComponentInterface;
use Psr\Container\ContainerInterface;

/**
 * Load PHP files referenced by a route into runtime objects: a page (class-
 * style {@see ComponentInterface} or function-style {@see FunctionPage}),
 * a {@see LayoutInterface}, or an error page ({@see ErrorPageComponent}).
 *
 * The loader resolves `.psx` sources to their compiled `.psx.php` siblings
 * through a caller-supplied closure (typically backed by {@see PsxCompiler})
 * and recovers the declared class name with {@see ClassFileScanner} after
 * `require_once`. Container-aware instantiation goes through
 * `container.get($class)` when the class is registered, otherwise
 * `new $class()` — the same fallback class-style pages have always used.
 *
 * The PSX-resolution step is injected as a closure (rather than a direct
 * {@see PsxCompiler} dependency) so the AppRouter can route resolution
 * through its protected `resolveCompiledPsxPath()` hook —
 * {@see TraceableAppRouter} overrides that hook to
 * wrap each compile in a profiler span, and a closure capturing AppRouter's
 * `$this` preserves the override polymorphically.
 */
final class ComponentLoader
{
    /** @var Closure(string): string */
    private readonly Closure $compilePsxPath;

    /**
     * Memoised factory closures returned by function-style page files,
     * keyed by their compiled (post-`.psx` resolution) path. See
     * {@see loadPage()} for the long-running-runtime contract this caches
     * around.
     *
     * @var array<string, Closure>
     */
    private array $factoryCache = [];

    /**
     * @param Closure(string): string $compilePsxPath given a `.psx` source
     *                                                path, return its compiled `.psx.php` path
     */
    public function __construct(
        Closure $compilePsxPath,
        private readonly ClassFileScanner $classScanner,
        private readonly FunctionPageBuilder $functionPageBuilder,
        private ?ContainerInterface $container = null,
    ) {
        $this->compilePsxPath = $compilePsxPath;
    }

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * @param array<string, string> $params
     */
    public function loadPage(
        string $pagePath,
        array $params,
        ?Request $currentRequest,
    ): ComponentInterface|FunctionPage|null {
        if (!\file_exists($pagePath)) {
            return null;
        }

        // The route-derived page id must be computed from the original
        // `src/Pages/.../page.psx` path — the compiled cache filename is an
        // opaque hash and would leak into action tokens / component state keys.
        $originalPagePath = $pagePath;

        if (\str_ends_with($pagePath, '.psx')) {
            $pagePath = ($this->compilePsxPath)($pagePath);
        }

        // require_once returns the file's return value the first time it
        // sees a path and `true` on every subsequent call — so a function-
        // style page would lose its factory closure from the second request
        // onward under a long-running runtime (PHP-FPM worker, swoole, …).
        // Cache the factory by compiled path so repeats keep working; the
        // class-style fallback below doesn't need this because it recovers
        // its class via the autoloader / class_exists.
        if (isset($this->factoryCache[$pagePath])) {
            return $this->functionPageBuilder->build(
                $this->factoryCache[$pagePath],
                $originalPagePath,
                $params,
                $currentRequest,
            );
        }

        $result = require_once $pagePath;

        // Closure return: function-based page.
        if ($result instanceof Closure) {
            $this->factoryCache[$pagePath] = $result;

            return $this->functionPageBuilder->build($result, $originalPagePath, $params, $currentRequest);
        }

        // Class-based page (fallback).
        $className = $this->classScanner->scan($pagePath);

        if (null !== $className && \class_exists($className)) {
            $instance = $this->instantiate($className);

            if ($instance instanceof ComponentInterface) {
                if ($instance instanceof PageComponent) {
                    $instance->setParams($params);
                }

                return $instance;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    public function loadLayout(string $filePath, array $params): ?LayoutInterface
    {
        if (!\file_exists($filePath)) {
            return null;
        }

        if (\str_ends_with($filePath, '.psx')) {
            $filePath = ($this->compilePsxPath)($filePath);
        }

        require_once $filePath;

        $className = $this->classScanner->scan($filePath);

        if (null === $className || !\class_exists($className)) {
            return null;
        }

        $instance = $this->instantiate($className);

        if (!$instance instanceof LayoutInterface) {
            return null;
        }

        if ($instance instanceof LayoutComponent) {
            $instance->setParams($params);
        }

        return $instance;
    }

    public function loadErrorPage(string $errorPath, int $statusCode, string $message): ?ComponentInterface
    {
        if (!\file_exists($errorPath)) {
            return null;
        }

        if (\str_ends_with($errorPath, '.psx')) {
            $errorPath = ($this->compilePsxPath)($errorPath);
        }

        require_once $errorPath;

        $className = $this->classScanner->scan($errorPath);

        if (null === $className || !\class_exists($className)) {
            return null;
        }

        $instance = $this->instantiate($className);

        if (!$instance instanceof ComponentInterface) {
            return null;
        }

        if ($instance instanceof ErrorPageComponent) {
            $instance->setError($statusCode, $message);
        }

        return $instance;
    }

    public function findRootLayoutPath(string $appDirectory): ?string
    {
        foreach (['layout.psx', 'layout.php'] as $name) {
            $candidate = $appDirectory . '/' . $name;
            if (\file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param class-string $className
     */
    private function instantiate(string $className): object
    {
        if (null !== $this->container && $this->container->has($className)) {
            $instance = $this->container->get($className);
            \assert(\is_object($instance));

            return $instance;
        }

        return new $className();
    }
}
