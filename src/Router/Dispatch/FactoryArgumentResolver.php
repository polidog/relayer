<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\PageContext;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;

/**
 * Reflection-based autowiring for the closures both function-style pages
 * (page factory) and API routes (per-method handler) expose.
 *
 * Wiring rules — applied to each typed, non-builtin parameter in order:
 *  1. {@see PageContext} (and subclasses)   → the per-request context.
 *  2. {@see Request}                         → the current request snapshot.
 *  3. {@see Identity}                        → the current principal. A non-
 *     nullable parameter on an anonymous request raises an
 *     {@see AuthorizationException} so the dispatcher turns it into a
 *     redirect / 401 / 403, mirroring the class-style `#[Auth]` attribute.
 *  4. anything the container knows about     → fetched from the container.
 *  5. otherwise default value, or `null` for a nullable parameter, or throw.
 *
 * Extracted from {@see AppRouter} so the same
 * autowire contract powers function-style pages and API handlers, with one
 * place to evolve the rules.
 */
final class FactoryArgumentResolver
{
    public function __construct(
        private readonly AuthenticatorLocator $authenticatorLocator,
        private ?ContainerInterface $container = null,
    ) {}

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * @return array<int, mixed>
     */
    public function resolve(
        Closure $callable,
        PageContext $context,
        string $pagePath,
        ?Request $currentRequest,
    ): array {
        $reflection = new ReflectionFunction($callable);
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

                if (Request::class === $typeName && null !== $currentRequest) {
                    $args[] = $currentRequest;

                    continue;
                }

                if (Identity::class === $typeName) {
                    $identity = $this->authenticatorLocator->resolve()?->user();
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

            // Neutral wording — this resolver autowires both function-style
            // pages and API route handlers, so naming "page" in the message
            // would mislead deployers when an API handler is the failing
            // callable. The path in $pagePath identifies which file.
            throw new RuntimeException(\sprintf(
                'Cannot autowire parameter $%s in %s: no type, default, or container binding.',
                $parameter->getName(),
                $pagePath,
            ));
        }

        return $args;
    }
}
