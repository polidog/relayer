<?php

declare(strict_types=1);

namespace App\Service;

use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\PasswordHasher;
use Polidog\Relayer\Auth\UserProvider;

/**
 * Demo {@see UserProvider} backed by a hard-coded in-memory user table.
 *
 * Passwords are hashed at construction so the rest of the app sees only
 * hashes — the same shape a real DB-backed provider would expose. Seed
 * accounts:
 *
 *   alice@example.com / secret123  (roles: user)
 *   admin@example.com / admin1234  (roles: user, admin)
 *
 * Also exposes a `register()` method used by the signup page so the
 * example can demonstrate the full sign-up → log-in flow end-to-end.
 * In a real app this would be a separate `UserRegistry` service that
 * writes to a database; folding it into the provider here keeps the
 * demo small.
 *
 * NOTE: in-memory state survives only as long as the PHP-FPM worker
 * keeps the service. Under the built-in dev server (process-per-request)
 * registrations are lost between requests, but the seeded accounts are
 * always available so the login flow can be tested without signing up.
 */
final class InMemoryUserProvider implements UserProvider
{
    /** @var array<string, array{id: int, name: string, hash: string, roles: array<string>}> */
    private array $usersByEmail;

    private int $nextId = 100;

    public function __construct(private readonly PasswordHasher $hasher)
    {
        $this->usersByEmail = [
            'alice@example.com' => [
                'id' => 1,
                'name' => 'Alice',
                'hash' => $hasher->hash('secret123'),
                'roles' => ['user'],
            ],
            'admin@example.com' => [
                'id' => 2,
                'name' => 'Admin',
                'hash' => $hasher->hash('admin1234'),
                'roles' => ['user', 'admin'],
            ],
        ];
    }

    public function findByIdentifier(string $identifier): ?Credentials
    {
        $key = self::normalize($identifier);
        $record = $this->usersByEmail[$key] ?? null;
        if (null === $record) {
            return null;
        }

        return new Credentials(
            identity: new Identity(
                id: $record['id'],
                displayName: $record['name'],
                roles: $record['roles'],
            ),
            passwordHash: $record['hash'],
        );
    }

    /**
     * Create a new account and return the resulting Identity. Returns
     * null when the email is already taken — callers should surface a
     * generic validation error rather than disclose the collision.
     */
    public function register(string $email, string $name, string $password): ?Identity
    {
        $key = self::normalize($email);
        if (isset($this->usersByEmail[$key])) {
            return null;
        }

        $id = $this->nextId++;
        $this->usersByEmail[$key] = [
            'id' => $id,
            'name' => $name,
            'hash' => $this->hasher->hash($password),
            'roles' => ['user'],
        ];

        return new Identity(id: $id, displayName: $name, roles: ['user']);
    }

    private static function normalize(string $email): string
    {
        return \strtolower(\trim($email));
    }
}
