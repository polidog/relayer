<?php

declare(strict_types=1);

namespace Polidog\Relayer\Di;

use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Makes every page, layout and error page in the container a non-shared
 * service, so `get()` builds a new object each time, and records which ids
 * are non-shared so the router knows it need not copy what it gets.
 *
 * A render writes to its page (params, request, scripts, metadata, what a
 * form action sets), and a page may build objects of its own in its
 * constructor (a form, a DTO). A shared service would be one object for the
 * worker's whole life, so all of that would reach the next visitor. Built
 * anew per request, the page's own state is fresh while the services
 * injected into it are still the container's shared ones.
 *
 * A page is recognised two ways:
 *  - its class is declared in a `page`, `layout` or `error` file (`.php` or
 *    `.psx`) under the app directory — the files the router loads. This
 *    covers a page that only implements `Renderable`, without touching any
 *    other `Renderable` (a component registered as a service stays shared);
 *  - its class extends `PageComponent` / `ErrorPageComponent` or implements
 *    `LayoutInterface`, wherever it is declared.
 *
 * This wins over an explicit `setShared(true)`: the framework writes to
 * these objects on every request, so sharing one is never safe. Runs as an
 * optimization pass, after child definitions and `_instanceof` conditionals
 * are resolved and before inlining/removal, so the dumped container
 * (`relayer container:compile`) gets the same factory-per-call wiring.
 *
 * Every non-shared service id (and each alias of one) ends up in the
 * {@see self::PARAMETER} parameter. Unlike a definition, a parameter
 * survives the dump, so {@see InjectorContainer} can tell a
 * new object from a shared one on the compiled container too.
 *
 * A synthetic service (an object handed over with `set()`) cannot be built
 * again and is left alone; the router clones it instead, and refuses one
 * that cannot be cloned.
 */
final class NonSharedPagesPass implements CompilerPassInterface
{
    public const string PARAMETER = 'relayer.non_shared_services';

    private const array PAGE_TYPES = [
        PageComponent::class,
        ErrorPageComponent::class,
        LayoutInterface::class,
    ];

    public function __construct(private readonly ?string $appDirectory = null) {}

    public function process(ContainerBuilder $container): void
    {
        $routeClasses = [];
        if (null !== $this->appDirectory) {
            foreach (RouteFileClasses::inAppDirectory($this->appDirectory) as $class) {
                $routeClasses[\strtolower($class)] = true;
            }
        }

        $nonShared = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract()) {
                continue;
            }

            if ($this->isPage($container, $definition->getClass(), $routeClasses)) {
                $definition->setShared(false);
            }

            if (!$definition->isShared()) {
                $nonShared[$id] = true;
            }
        }

        foreach (\array_keys($container->getAliases()) as $alias) {
            try {
                $definition = $container->findDefinition($alias);
            } catch (Throwable) {
                continue;
            }

            if (!$definition->isShared() && !$definition->isSynthetic()) {
                $nonShared[$alias] = true;
            }
        }

        $container->setParameter(self::PARAMETER, \array_map('strval', \array_keys($nonShared)));
    }

    /**
     * @param array<string, true> $routeClasses lower-cased class names
     */
    private function isPage(ContainerBuilder $container, ?string $class, array $routeClasses): bool
    {
        if (null === $class) {
            return false;
        }

        try {
            $class = $container->getParameterBag()->resolveValue($class);
        } catch (Throwable) {
            return false;
        }

        if (!\is_string($class)) {
            return false;
        }

        $class = \ltrim($class, '\\');

        if (isset($routeClasses[\strtolower($class)])) {
            return true;
        }

        if (!\class_exists($class)) {
            return false;
        }

        foreach (self::PAGE_TYPES as $type) {
            if (\is_a($class, $type, true)) {
                return true;
            }
        }

        return false;
    }
}
