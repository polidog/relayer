<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Session-based authentication contract.
 *
 * Implemented by the concrete {@see Authenticator} and decorated in dev
 * by {@see TraceableAuthenticator}. Framework code (AppRouter,
 * PageContext, InjectorContainer) type-hints against this interface so
 * the decorator can be swapped in transparently — apps that want
 * profiling on auth events should also depend on this interface rather
 * than the concrete class.
 */
interface AuthenticatorInterface
{
    /**
     * Verify credentials and, on success, log the user in. Returns null
     * on any failure (unknown user or wrong password). Failure reasons
     * are intentionally not distinguished so attackers cannot enumerate
     * valid identifiers.
     */
    public function attempt(string $identifier, string $password): ?Identity;

    /**
     * Promote an already-resolved {@see Identity} to the current session
     * (e.g. after a social login flow). Rotates the session id.
     */
    public function login(Identity $identity): void;

    /**
     * Clear the current session principal and rotate the session id.
     */
    public function logout(): void;

    /**
     * Return the currently authenticated principal, or null when no one
     * is logged in.
     */
    public function user(): ?Identity;

    public function check(): bool;

    public function hasRole(string $role): bool;

    /**
     * @param array<string> $roles
     */
    public function hasAnyRole(array $roles): bool;
}
