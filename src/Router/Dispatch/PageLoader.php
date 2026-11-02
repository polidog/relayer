<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Psx\PsxCache;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;

/**
 * Turns the files a route points at into page and layout objects, autowiring
 * their arguments. Holds no request state, so it can live as long as the router.
 *
 * @internal
 */
final class PageLoader
{
    /**
     * Function-style page factories keyed by the (compiled) file path —
     * see {@see loadPageInternal()} for why `require_once` alone is not
     * enough once the process outlives a single request. Static because what
     * `require_once` remembers is per process: a loader built later (the
     * router rebuilds it when its container or profiler changes) gets `true`
     * for a file an earlier one already included.
     *
     * @var array<string, Closure>
     */
    private static array $pageFactories = [];

    public function __construct(
        private readonly string $appDirectory,
        private readonly ?ContainerInterface $container,
        private readonly PsxCache $psxCache,
        private readonly ?Profiler $profiler,
    ) {}

    /**
     * @param array<string>         $layoutPaths
     * @param array<string, string> $params
     */
    public function loadLayouts(array $layoutPaths, array $params): LayoutStack
    {
        $stack = new LayoutStack();

        foreach ($layoutPaths as $layoutPath) {
            $layout = $this->loadLayoutFromFile($layoutPath, $params);
            if (null !== $layout) {
                $stack->push($layout);
            }
        }

        return $stack;
    }

    /**
     * @param array<string, string> $params
     */
    public function loadPage(string $pagePath, array $params, Request $request): FunctionPage|Renderable|null
    {
        $result = $this->loadPageInternal($pagePath, $params, $request);

        if (null !== $this->profiler) {
            $kind = match (true) {
                $result instanceof FunctionPage => 'function',
                $result instanceof Renderable => 'class',
                default => 'null',
            };
            $this->profiler->collect('page', 'load', [
                'pagePath' => $pagePath,
                'kind' => $kind,
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, string> $params
     */
    public function loadLayoutFromFile(string $filePath, array $params): ?LayoutInterface
    {
        $layout = $this->loadLayoutFromFileInternal($filePath, $params);
        $this->profiler?->collect('layout', 'load', [
            'filePath' => $filePath,
            'loaded' => null !== $layout,
        ]);

        return $layout;
    }

    public function loadErrorPage(string $errorPath, int $statusCode, string $message): ?Renderable
    {
        if (!\file_exists($errorPath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($errorPath, '.psx')) {
            $errorPath = $this->psxCache->compiledPath($errorPath, $this->profiler);
        }

        require_once $errorPath;

        $className = $this->getClassFromFile($errorPath);

        if (null === $className) {
            return null;
        }

        if (!\class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof Renderable) {
            return null;
        }

        if ($instance instanceof ErrorPageComponent) {
            $instance->setError($statusCode, $message);
        }

        return $instance;
    }

    public function findRootLayoutPath(): ?string
    {
        foreach (['layout.psx', 'layout.php'] as $name) {
            $candidate = $this->appDirectory . '/' . $name;
            if (\file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Reflection-based autowiring for a function-style page's factory closure.
     * `PageContext` parameters receive the per-request context; every other
     * typed parameter is resolved from the container, matching the constructor
     * injection class-style pages already get.
     *
     * @return array<int, mixed>
     */
    public function resolveFactoryArguments(Closure $factory, PageContext $context, string $pagePath, Request $request): array
    {
        $reflection = new ReflectionFunction($factory);
        $args = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if (PageContext::class === $typeName
                    || \is_subclass_of($typeName, PageContext::class)
                ) {
                    $args[] = $context;

                    continue;
                }

                if (Request::class === $typeName) {
                    $args[] = $request;

                    continue;
                }

                if (Identity::class === $typeName) {
                    // Inject the current principal (or null when no one is
                    // logged in). A non-nullable `Identity` parameter on a
                    // page implies the page is auth-required — surface the
                    // misuse as an AuthorizationException so the router
                    // turns it into a redirect / 401, mirroring the
                    // class-style #[Auth] attribute.
                    $identity = $this->resolveAuthenticator()?->user();
                    if (null === $identity && !$parameter->allowsNull()) {
                        throw new AuthorizationException(
                            AuthGuard::DECISION_REDIRECT,
                        );
                    }
                    $args[] = $identity;

                    continue;
                }

                if (null !== $this->container && $this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);

                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Cannot autowire parameter $%s of function-style page %s: no type, default, or container binding.',
                $parameter->getName(),
                $pagePath,
            ));
        }

        return $args;
    }

    public function computePageId(string $pagePath): string
    {
        $relative = \str_replace($this->appDirectory, '', $pagePath);
        $relative = (string) \preg_replace('#/(?:page|route)\.(psx\.php|psx|php)$#', '', $relative);

        if ('' === $relative || '/' === $relative) {
            return '/';
        }

        return $relative;
    }

    public function resolveAuthenticator(): ?AuthenticatorInterface
    {
        // UserProvider is an interface — `has()` only returns true when
        // the app explicitly bound an implementation. Used as the gate
        // for "auth is configured" so apps without auth pay nothing.
        if (null === $this->container || !$this->container->has(UserProvider::class)) {
            return null;
        }
        if (!$this->container->has(AuthenticatorInterface::class)) {
            return null;
        }

        $auth = $this->container->get(AuthenticatorInterface::class);

        return $auth instanceof AuthenticatorInterface ? $auth : null;
    }

    /**
     * The unwrapped loader; {@see loadPage()} is the profiler-aware
     * facade. Split so the profile event fires once per attempted load
     * with the final result (function / class / null), not at every
     * intermediate return.
     *
     * @param array<string, string> $params
     */
    private function loadPageInternal(string $pagePath, array $params, Request $request): FunctionPage|Renderable|null
    {
        if (!\file_exists($pagePath)) {
            return null;
        }

        // The route-derived page id must be computed from the original
        // src/Pages/.../page.psx path — the compiled cache filename is an
        // opaque hash and would leak into action tokens / component state keys.
        $originalPagePath = $pagePath;

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($pagePath, '.psx')) {
            $pagePath = $this->psxCache->compiledPath($pagePath, $this->profiler);
        }

        // Function-style pages return their factory Closure from the file.
        // `require_once` yields that Closure only on the FIRST include; on
        // every later include of the same file it returns `true`, so under
        // a long-running runtime (FrankenPHP worker, RoadRunner) the second
        // request for the same page would fall through to the class lookup,
        // find nothing, and 404. Keep the factory per compiled path — it is
        // invoked afresh for every request, so caching it carries no
        // per-request state.
        if (isset(self::$pageFactories[$pagePath])) {
            return $this->buildFunctionPage(self::$pageFactories[$pagePath], $originalPagePath, $params, $request);
        }

        $result = require_once $pagePath;

        // Closure return: function-based page
        if ($result instanceof Closure) {
            self::$pageFactories[$pagePath] = $result;

            return $this->buildFunctionPage($result, $originalPagePath, $params, $request);
        }

        // Class-based page (fallback)
        $className = $this->getClassFromFile($pagePath);

        if (null !== $className && \class_exists($className)) {
            $instance = $this->resolveInstance($className);

            if ($instance instanceof Renderable) {
                if ($instance instanceof PageComponent) {
                    $instance->setParams($params);
                    $instance->setRequest($request);
                }

                return $instance;
            }
        }

        return null;
    }

    /**
     * The unwrapped loader; {@see loadLayoutFromFile()} is the
     * profiler-aware facade. Same split as
     * {@see loadPage()}/{@see loadPageInternal()}.
     *
     * @param array<string, string> $params
     */
    private function loadLayoutFromFileInternal(string $filePath, array $params): ?LayoutInterface
    {
        if (!\file_exists($filePath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($filePath, '.psx')) {
            $filePath = $this->psxCache->compiledPath($filePath, $this->profiler);
        }

        require_once $filePath;

        $className = $this->getClassFromFile($filePath);

        if (null === $className) {
            return null;
        }

        if (!\class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof LayoutInterface) {
            return null;
        }

        if ($instance instanceof LayoutComponent) {
            $instance->setParams($params);
        }

        return $instance;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildFunctionPage(Closure $factory, string $pagePath, array $params, Request $request): FunctionPage
    {
        $pageId = $this->computePageId($pagePath);
        $context = new PageContext($params, $pageId);
        PageContext::setCurrent($context);
        $context->setAuthenticator($this->resolveAuthenticator());
        $args = $this->resolveFactoryArguments($factory, $context, $pagePath, $request);
        $result = $factory(...$args);

        // Two-level form: factory returns the render closure. Standard pattern
        // used when the page needs to declare cache policy / metadata / etc.
        // before the render body executes.
        if ($result instanceof Closure) {
            $renderFn = $result;
        // Single-level shorthand: factory IS the render — it returned an
        // Element directly. Re-wrap in a no-op closure so the same FunctionPage
        // contract works downstream.
        } elseif ($result instanceof Element) {
            $renderFn = static fn (): Element => $result;
        } else {
            throw new RuntimeException("Page factory must return a Closure or Element: {$pagePath}");
        }

        return new FunctionPage($renderFn, $context, $pageId, $request);
    }

    /**
     * Resolve a class instance using the container or direct instantiation.
     *
     * A page, layout or error page from the container is cloned. A service is
     * usually shared, so the object the container holds lives as long as the
     * worker, while a render writes to the page (params, request, scripts,
     * metadata, children, whatever a form action sets). The clone keeps the
     * dependencies the container injected, and the service itself is never
     * written to, so each request starts from the same state. The clone is
     * shallow: an object the page built for itself in its constructor is
     * still shared between requests.
     *
     * @param class-string $className
     */
    private function resolveInstance(string $className): object
    {
        if (null !== $this->container && $this->container->has($className)) {
            $instance = $this->container->get($className);
            \assert(\is_object($instance));

            return $instance instanceof Renderable || $instance instanceof LayoutInterface
                ? clone $instance
                : $instance;
        }

        return new $className();
    }

    private function getClassFromFile(string $filePath): ?string
    {
        $content = \file_get_contents($filePath);

        if (false === $content) {
            return null;
        }

        $tokens = \token_get_all($content);
        $tokenCount = \count($tokens);
        $namespace = null;
        $className = null;

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespaceParts = [];
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (';' === $nextToken || '{' === $nextToken) {
                        break;
                    }

                    if (\is_array($nextToken)) {
                        if (\T_NAME_QUALIFIED === $nextToken[0] || \T_STRING === $nextToken[0]) {
                            $namespaceParts[] = $nextToken[1];
                        }
                    }

                    ++$i;
                }

                $namespace = \implode('', $namespaceParts);
            }

            if (\T_CLASS === $token[0]) {
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (\is_array($nextToken) && \T_STRING === $nextToken[0]) {
                        $className = $nextToken[1];

                        break;
                    }

                    if (\is_array($nextToken) && \T_WHITESPACE === $nextToken[0]) {
                        ++$i;

                        continue;
                    }

                    break;
                }

                if (null !== $className) {
                    break;
                }
            }
        }

        if (null === $className) {
            return null;
        }

        if (null !== $namespace) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }
}
