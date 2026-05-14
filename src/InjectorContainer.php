<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
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
    public function __construct(private readonly SymfonyContainerInterface $container) {}

    public function has(string $id): bool
    {
        return $this->container->has($id) || \class_exists($id);
    }

    public function get(string $id): object
    {
        // Conditional GET short-circuit: evaluate #[Cache] BEFORE we resolve
        // the page so a 304 response never instantiates the page or its
        // dependencies (the whole point of a fast ETag store).
        if (\is_subclass_of($id, PageComponent::class)) {
            $cache = CachePolicy::applyFromAttribute($id, $this->resolveEtagStore());
            if (null !== $cache && CachePolicy::isNotModified($cache)) {
                CachePolicy::sendNotModified();

                exit;
            }
        }

        return $this->resolve($id);
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
