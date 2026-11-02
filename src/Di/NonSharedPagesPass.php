<?php

declare(strict_types=1);

namespace Polidog\Relayer\Di;

use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * Makes every page, layout and error page in the container a non-shared
 * service, so `get()` builds a new object each time.
 *
 * A render writes to its page (params, request, scripts, metadata, what a
 * form action sets), and a page may build objects of its own in its
 * constructor (a form, a DTO). A shared service would be one object for the
 * worker's whole life, so all of that would reach the next visitor. Built
 * anew per request, the page's own state is fresh while the services
 * injected into it are still the container's shared ones.
 *
 * This wins over an explicit `setShared(true)`: the framework writes to
 * these objects on every request, so sharing one is never safe. Runs as an
 * optimization pass, after child definitions and `_instanceof` conditionals
 * are resolved and before inlining/removal, so the dumped container
 * (`relayer container:compile`) gets the same factory-per-call wiring.
 *
 * A synthetic service (an object handed over with `set()`) cannot be built
 * again and is left alone; the router still clones what it gets so the
 * container's object is never written to.
 */
final class NonSharedPagesPass implements CompilerPassInterface
{
    private const array PAGE_TYPES = [
        PageComponent::class,
        ErrorPageComponent::class,
        LayoutInterface::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract()) {
                continue;
            }

            $class = $definition->getClass();
            if (null === $class) {
                continue;
            }

            try {
                $class = $container->getParameterBag()->resolveValue($class);
            } catch (Throwable) {
                continue;
            }

            if (!\is_string($class) || !\class_exists($class)) {
                continue;
            }

            foreach (self::PAGE_TYPES as $type) {
                if (\is_a($class, $type, true)) {
                    $definition->setShared(false);

                    break;
                }
            }
        }
    }
}
