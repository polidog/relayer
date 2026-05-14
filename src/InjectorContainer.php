<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Component\PageComponent;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

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
            if (null !== $authenticator && !AuthGuard::enforce($id, $authenticator, $this->currentRequest?->path)) {
                exit;
            }

            // Conditional GET short-circuit: evaluate #[Cache] BEFORE we
            // instantiate the page so a 304 response never builds the
            // page or its dependencies (the whole point of a fast ETag
            // store).
            $cache = CachePolicy::applyFromAttribute($id, $this->resolveEtagStore());
            if (null !== $cache && CachePolicy::isNotModified($cache)) {
                CachePolicy::sendNotModified();

                exit;
            }
        }

        return $this->resolve($id);
    }

    private function resolveAuthenticator(): ?Authenticator
    {
        // Gate on UserProvider — an unbound interface signals "auth not
        // configured" and lets apps without auth skip the whole code path.
        if (!$this->container->has(UserProvider::class) || !$this->container->has(Authenticator::class)) {
            return null;
        }

        $auth = $this->container->get(Authenticator::class);

        return $auth instanceof Authenticator ? $auth : null;
    }

    private function resolveEtagStore(): ?EtagStore
    {
        if (!$this->container->has(EtagStore::class)) {
            return null;
        }

        $store = $this->container->get(EtagStore::class);

        return $store instanceof EtagStore ? $store : null;
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
