<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\UserProvider;
use Psr\Container\ContainerInterface;

/**
 * Locate the configured {@see AuthenticatorInterface} from the container, or
 * report `null` so an unconfigured app pays nothing.
 *
 * The {@see UserProvider} binding is the gate for "auth is configured" — its
 * presence implies the app opted in. An app without auth bound, or with
 * either binding missing, gets `null` and dispatchers fall back to anonymous.
 */
final class AuthenticatorLocator
{
    public function __construct(
        private ?ContainerInterface $container = null,
    ) {}

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function resolve(): ?AuthenticatorInterface
    {
        if (null === $this->container || !$this->container->has(UserProvider::class)) {
            return null;
        }
        if (!$this->container->has(AuthenticatorInterface::class)) {
            return null;
        }

        $auth = $this->container->get(AuthenticatorInterface::class);

        return $auth instanceof AuthenticatorInterface ? $auth : null;
    }
}
