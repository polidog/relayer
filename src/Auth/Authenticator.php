<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use LogicException;

/**
 * Session-based authentication orchestrator.
 *
 * Public surface intentionally mirrors the small set of operations a typical
 * server-rendered app needs:
 *
 * - `attempt()` — verify identifier+password against the configured
 *   {@see UserProvider} and, on success, log the user in.
 * - `login()` — promote an already-resolved {@see Identity} to the
 *   current session (e.g. after social login or a verified bearer token).
 * - `logout()` — drop the principal and rotate the session id.
 * - `user()` / `check()` / `hasRole()` — read-only state for pages.
 *
 * The {@see UserProvider} (and its {@see PasswordHasher}) are optional:
 * `login()` / `logout()` / `user()` / `check()` only touch the session,
 * so a token-first app (Firebase/Cognito, no local password store) can
 * verify a JWT with a {@see Token\TokenVerifier} and call `login()` to
 * get a cookie session without ever wiring a `UserProvider`. `attempt()`
 * is the only password-path operation; calling it without a provider is
 * a wiring mistake and fails loudly rather than silently rejecting.
 *
 * State is kept in the {@see SessionStorage} under a single namespaced
 * key (`relayer.auth.identity`). The stored payload is the array form of
 * {@see Identity} — no password or password hash is ever persisted
 * client-side.
 *
 * Session id rotation happens on both login and logout to defend against
 * session fixation: a captured pre-login id stops working the moment the
 * user authenticates.
 */
final class Authenticator implements AuthenticatorInterface
{
    private const SESSION_KEY = 'relayer.auth.identity';

    private ?Identity $cached = null;
    private bool $cacheLoaded = false;
    private ?string $dummyHash = null;

    /**
     * `$session` is required (it is the whole point of this class).
     * `$users`/`$hasher` are optional so a token-first app gets a working
     * session authenticator without a password store; they are always
     * paired — `attempt()` needs both or neither.
     */
    public function __construct(
        private readonly SessionStorage $session,
        private readonly ?UserProvider $users = null,
        private readonly ?PasswordHasher $hasher = null,
    ) {}

    /**
     * Try to log in with a credential pair. Returns the resolved
     * {@see Identity} on success, null on any failure (unknown user,
     * wrong password). The reason for failure is deliberately not
     * exposed — callers should render a single generic error so
     * attackers cannot distinguish "no such user" from "wrong password".
     *
     * @throws LogicException when no {@see UserProvider}/{@see PasswordHasher}
     *                        is configured — that is a wiring error, not a
     *                        failed login, so it must not be swallowed into
     *                        an indistinguishable null
     */
    public function attempt(string $identifier, string $password): ?Identity
    {
        $users = $this->users;
        $hasher = $this->hasher;
        if (null === $users || null === $hasher) {
            throw new LogicException('Authenticator::attempt() requires a UserProvider and PasswordHasher; none are configured. This instance is wired for token/social login only — verify the credential elsewhere and call login() with the resulting Identity.');
        }

        $credentials = $users->findByIdentifier($identifier);
        if (null === $credentials) {
            // Equalise the response time between "user not found" and
            // "user found but wrong password" so an attacker cannot use
            // timing to enumerate valid identifiers. The dummy hash is a
            // real hash produced by the configured hasher so verify()
            // does the full algorithmic work.
            $hasher->verify($password, $this->getDummyHash($hasher));

            return null;
        }

        if (!$hasher->verify($password, $credentials->passwordHash)) {
            return null;
        }

        $this->login($credentials->identity);

        return $credentials->identity;
    }

    public function login(Identity $identity): void
    {
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $identity->toArray());
        $this->cached = $identity;
        $this->cacheLoaded = true;
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerateId();
        $this->cached = null;
        $this->cacheLoaded = true;
    }

    public function user(): ?Identity
    {
        if ($this->cacheLoaded) {
            return $this->cached;
        }

        $raw = $this->session->get(self::SESSION_KEY);
        $this->cached = \is_array($raw) ? Identity::fromArray($raw) : null;
        $this->cacheLoaded = true;

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

    /**
     * Lazily produce a real hash via the configured hasher. Computed
     * once per Authenticator instance and reused so the cost is paid
     * only on the first unknown-identifier attempt within a request.
     * Takes the hasher explicitly: it is only reachable from
     * {@see attempt()} past the null-guard, so the type stays non-null.
     */
    private function getDummyHash(PasswordHasher $hasher): string
    {
        // Plain ASCII so bcrypt accepts it (bcrypt rejects null bytes).
        return $this->dummyHash ??= $hasher->hash('relayer-timing-equaliser');
    }
}
