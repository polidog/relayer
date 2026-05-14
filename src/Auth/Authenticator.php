<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Session-based authentication orchestrator.
 *
 * Public surface intentionally mirrors the small set of operations a typical
 * server-rendered app needs:
 *
 * - `attempt()` — verify identifier+password against the configured
 *   {@see UserProvider} and, on success, log the user in.
 * - `login()` — promote an already-resolved {@see Identity} to the
 *   current session (e.g. after social login).
 * - `logout()` — drop the principal and rotate the session id.
 * - `user()` / `check()` / `hasRole()` — read-only state for pages.
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

    public function __construct(
        private readonly UserProvider $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionStorage $session,
    ) {}

    /**
     * Try to log in with a credential pair. Returns the resolved
     * {@see Identity} on success, null on any failure (unknown user,
     * wrong password). The reason for failure is deliberately not
     * exposed — callers should render a single generic error so
     * attackers cannot distinguish "no such user" from "wrong password".
     */
    public function attempt(string $identifier, string $password): ?Identity
    {
        $credentials = $this->users->findByIdentifier($identifier);
        if (null === $credentials) {
            // Equalise the response time between "user not found" and
            // "user found but wrong password" so an attacker cannot use
            // timing to enumerate valid identifiers. The dummy hash is a
            // real hash produced by the configured hasher so verify()
            // does the full algorithmic work.
            $this->hasher->verify($password, $this->getDummyHash());

            return null;
        }

        if (!$this->hasher->verify($password, $credentials->passwordHash)) {
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
     */
    private function getDummyHash(): string
    {
        // Plain ASCII so bcrypt accepts it (bcrypt rejects null bytes).
        return $this->dummyHash ??= $this->hasher->hash('relayer-timing-equaliser');
    }
}
