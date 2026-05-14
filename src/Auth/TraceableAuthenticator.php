<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Profiler\Profiler;

/**
 * Dev-only {@see AuthenticatorInterface} decorator that records auth
 * lifecycle events into the request-scoped {@see Profiler}.
 *
 * Read paths (`user()`, `check()`, `hasRole()`) are intentionally NOT
 * traced — they're called repeatedly per request and would drown out
 * the interesting events. State-changing operations (`attempt()`,
 * `login()`, `logout()`) ARE traced because each one represents a
 * security-relevant transition.
 *
 * Passwords are never recorded. The `attempt` event keeps only the
 * identifier and the boolean outcome.
 */
final class TraceableAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly AuthenticatorInterface $inner,
        private readonly Profiler $profiler,
    ) {}

    public function attempt(string $identifier, string $password): ?Identity
    {
        $span = $this->profiler->start('auth', 'attempt');
        $identity = $this->inner->attempt($identifier, $password);
        $span->stop([
            'identifier' => $identifier,
            'success' => null !== $identity,
        ]);

        return $identity;
    }

    public function login(Identity $identity): void
    {
        $this->inner->login($identity);
        $this->profiler->collect('auth', 'login', [
            'id' => $identity->id,
            'roles' => $identity->roles,
        ]);
    }

    public function logout(): void
    {
        $hadUser = $this->inner->check();
        $this->inner->logout();
        $this->profiler->collect('auth', 'logout', [
            'hadUser' => $hadUser,
        ]);
    }

    public function user(): ?Identity
    {
        return $this->inner->user();
    }

    public function check(): bool
    {
        return $this->inner->check();
    }

    public function hasRole(string $role): bool
    {
        return $this->inner->hasRole($role);
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->inner->hasAnyRole($roles);
    }
}
