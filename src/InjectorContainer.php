<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Di\NonSharedPagesPass;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\StopRequest;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * PSR-11 adapter over the Symfony DI container.
 *
 * AppRouter resolves Page/Layout instances by calling
 * `$container->has($className)` then `$container->get($className)`.
 * The Symfony container only returns true for explicitly registered services,
 * so unregistered Page classes would slip through to AppRouter's
 * `new $className()` fallback and miss constructor injection.
 *
 * This adapter widens `has()` to "registered service or loadable class".
 * Registered classes go through Symfony (full DI features), and ad-hoc Page
 * classes fall back to a reflection-based autowire so they still receive
 * their constructor dependencies from the container.
 */
final class InjectorContainer implements ContainerInterface
{
    private ?Request $currentRequest = null;

    public function __construct(private readonly SymfonyContainerInterface $container) {}

    /**
     * Set the current request snapshot for the duration of a dispatch.
     * AppRouter calls this in `run()` and clears it on exit so per-request
     * state doesn't leak across long-lived processes (workerman, RoadRunner).
     */
    public function setCurrentRequest(?Request $request): void
    {
        $this->currentRequest = $request;
    }

    public function has(string $id): bool
    {
        if (Request::class === $id) {
            return null !== $this->currentRequest;
        }

        return $this->container->has($id) || \class_exists($id);
    }

    /**
     * Whether {@see get()} builds a new object for `$id` on every call, so
     * the caller owns what it gets and need not copy it.
     *
     * True for a class that is not registered (it is autowired here, anew
     * each time) and for a non-shared definition. The container built by
     * {@see ContainerFactory} lists its non-shared ids in a parameter that
     * survives `relayer container:compile`; a bare {@see ContainerBuilder}
     * is asked for the definition itself. Anything else — a shared service,
     * an object put in with `set()` — may be the same object every time.
     */
    public function buildsNewInstance(string $id): bool
    {
        if (Request::class === $id) {
            return false;
        }

        if (!$this->container->has($id)) {
            return \class_exists($id);
        }

        if ($this->container instanceof ContainerBuilder) {
            try {
                $definition = $this->container->findDefinition($id);
            } catch (ServiceNotFoundException) {
                // An object set() on the builder without a definition.
                return false;
            }

            return !$definition->isShared() && !$definition->isSynthetic();
        }

        if (!$this->container->hasParameter(NonSharedPagesPass::PARAMETER)) {
            return false;
        }

        $nonShared = $this->container->getParameter(NonSharedPagesPass::PARAMETER);

        return \is_array($nonShared) && \in_array($id, $nonShared, true);
    }

    public function get(string $id): object
    {
        if (Request::class === $id) {
            if (null === $this->currentRequest) {
                throw new class('No current Request — AppRouter must call setCurrentRequest() before resolving Request.') extends RuntimeException implements NotFoundExceptionInterface {};
            }

            return $this->currentRequest;
        }

        if (\is_subclass_of($id, PageComponent::class)) {
            // Auth gate BEFORE cache: an unauthorized request should not
            // produce a cache hit (304) that could later be served by a
            // shared cache to an anonymous viewer. We also evaluate it
            // before resolving the page, so a redirect never touches the
            // page's dependencies.
            $authenticator = $this->resolveAuthenticator();
            if (null !== $authenticator && !AuthGuard::enforce($id, $authenticator, $this->currentRequest, $this->resolveLocaleResolver())) {
                throw new StopRequest();
            }

            // Conditional GET short-circuit: evaluate #[Cache] BEFORE we
            // instantiate the page so a 304 response never builds the
            // page or its dependencies (the whole point of a fast ETag
            // store).
            $cache = CachePolicy::applyFromAttribute($id, $this->resolveEtagStore());
            if (null !== $cache) {
                $profiler = $this->resolveProfiler();
                $profiler?->collect('cache', 'apply', [
                    'source' => 'attribute',
                    'etag' => $cache->etag,
                    'etagKey' => $cache->etagKey,
                    'lastModified' => $cache->lastModified,
                    'maxAge' => $cache->maxAge,
                    'sMaxAge' => $cache->sMaxAge,
                    'directives' => CachePolicy::buildDirectives($cache),
                ]);

                if (CachePolicy::isNotModified($cache, $this->currentRequest)) {
                    $profiler?->collect('cache', 'hit_304', [
                        'etag' => $cache->etag,
                    ]);
                    CachePolicy::sendNotModified();

                    // Not exit: that would restart a worker, and every
                    // cleanup on the way out would be skipped.
                    throw new StopRequest();
                }
            }
        }

        return $this->resolve($id);
    }

    private function resolveLocaleResolver(): ?LocaleResolver
    {
        if (!$this->container->has(LocaleResolver::class)) {
            return null;
        }

        $locales = $this->container->get(LocaleResolver::class);

        return $locales instanceof LocaleResolver ? $locales : null;
    }

    private function resolveAuthenticator(): ?AuthenticatorInterface
    {
        // The AuthenticatorInterface binding itself is the "auth
        // configured" signal: ContainerFactory only aliases it when a
        // UserProvider (session) or a TokenVerifier (stateless) is bound,
        // so apps without auth still skip the whole code path — without
        // assuming the password-based UserProvider is the only model.
        if (!$this->container->has(AuthenticatorInterface::class)) {
            return null;
        }

        $auth = $this->container->get(AuthenticatorInterface::class);

        return $auth instanceof AuthenticatorInterface ? $auth : null;
    }

    private function resolveEtagStore(): ?EtagStore
    {
        if (!$this->container->has(EtagStore::class)) {
            return null;
        }

        $store = $this->container->get(EtagStore::class);

        return $store instanceof EtagStore ? $store : null;
    }

    private function resolveProfiler(): ?Profiler
    {
        if (!$this->container->has(Profiler::class)) {
            return null;
        }

        $profiler = $this->container->get(Profiler::class);

        return $profiler instanceof Profiler ? $profiler : null;
    }

    private function resolve(string $id): object
    {
        if ($this->container->has($id)) {
            return $this->container->get($id);
        }

        if (!\class_exists($id)) {
            throw new class("Service or class not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->autowire($id);
    }

    /**
     * Reflection-based constructor autowiring for classes not registered in
     * the container. Each non-builtin typed parameter is resolved recursively
     * via `$this->get()`; parameters with defaults fall back to their default.
     *
     * @param class-string $id
     */
    private function autowire(string $id): object
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new class("Class is not instantiable: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
        }

        $constructor = $reflection->getConstructor();
        if (null === $constructor || 0 === $constructor->getNumberOfParameters()) {
            return new $id();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());

                continue;
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
                'Cannot autowire parameter $%s of %s: no type, default, or container binding.',
                $parameter->getName(),
                $id,
            ));
        }

        return $reflection->newInstanceArgs($args);
    }
}
