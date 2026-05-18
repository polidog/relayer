<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use LogicException;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\Identity;

/**
 * Stateless {@see AuthenticatorInterface}: every request re-derives the
 * principal from the `Authorization: Bearer <jwt>` header via a
 * {@see TokenVerifier}. Nothing is persisted between requests — there is
 * no session, no cookie, no login/logout step.
 *
 * This is the API-style half of the two supported modes. Bound as
 * `AuthenticatorInterface` it makes the existing `#[Auth]` machinery work
 * unchanged: `#[Auth(redirectTo: '')]` yields a clean 401 for an
 * anonymous or bad-token request, and `#[Auth(roles: [...])]` enforces
 * roles carried in the token. The session-login mode is intentionally
 * *not* this class — for that, an app verifies the token with a
 * {@see TokenVerifier} and calls {@see Authenticator::login()}
 * to mint a normal cookie session.
 *
 * The verified principal is memoized for the lifetime of the instance,
 * which the container scopes to one request, so a page that checks auth
 * several times verifies the JWT once.
 */
final class TokenAuthenticator implements AuthenticatorInterface
{
    private ?Identity $cached = null;

    private bool $resolved = false;

    public function __construct(
        private readonly TokenVerifier $verifier,
        private readonly AuthorizationHeader $header,
    ) {}

    /**
     * Unsupported: bearer auth has no identifier+password handshake.
     * Calling this signals the wrong auth model is wired for the route,
     * so it fails loudly rather than returning a misleading null.
     */
    public function attempt(string $identifier, string $password): ?Identity
    {
        throw new LogicException('TokenAuthenticator is bearer-token only; attempt() has no meaning. Use a password UserProvider/Authenticator for credential login.');
    }

    /**
     * Unsupported: a stateless authenticator has nowhere to persist a
     * principal. To turn a verified token into a session, depend on the
     * session {@see Authenticator} and call its
     * `login()` with the {@see Identity} a {@see TokenVerifier} returned.
     */
    public function login(Identity $identity): void
    {
        throw new LogicException('TokenAuthenticator is stateless; login() cannot persist a session. Verify the token then call Authenticator::login() for session mode.');
    }

    /**
     * Unsupported: there is no server-side session to clear. A bearer
     * client logs out by discarding its token.
     */
    public function logout(): void
    {
        throw new LogicException('TokenAuthenticator is stateless; logout() has nothing to clear. A bearer client discards its token.');
    }

    public function user(): ?Identity
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $token = BearerToken::parse($this->header->value());
        $this->cached = null === $token ? null : $this->verifier->verify($token);
        $this->resolved = true;

        return $this->cached;
    }

    public function check(): bool
    {
        return null !== $this->user();
    }

    public function hasRole(string $role): bool
    {
        $user = $this->user();

        return null !== $user && $user->hasRole($role);
    }

    /**
     * @param array<string> $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        if ([] === $roles) {
            return true;
        }
        $user = $this->user();
        if (null === $user) {
            return false;
        }
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
