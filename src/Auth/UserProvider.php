<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Pluggable user lookup used by {@see Authenticator::attempt()}.
 *
 * Implementations translate the user-supplied identifier (typically an
 * email or username) into a {@see Credentials} pair so the framework can
 * verify the submitted password without ever seeing the storage layer.
 *
 * Apps register a single implementation in their `AppConfigurator` (or
 * `config/services.yaml`); the framework resolves it via autowiring.
 */
interface UserProvider
{
    /**
     * Return credentials for the given identifier, or null when no such
     * user exists.
     *
     * Implementations should return null (not throw) when the identifier
     * is unknown — the authenticator treats null as "login failed" without
     * leaking the reason to the caller.
     */
    public function findByIdentifier(string $identifier): ?Credentials;
}
