<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Default {@see PasswordHasher} backed by PHP's `password_hash`.
 *
 * Uses `PASSWORD_DEFAULT` so the algorithm tracks whatever PHP considers
 * strongest on the current build (bcrypt today, may become argon2id in
 * a future PHP version). Apps that want to force a specific algorithm —
 * e.g. argon2id when libargon2 is available — can pass the constant
 * (or any value `password_hash` accepts) to the constructor.
 */
final class NativePasswordHasher implements PasswordHasher
{
    /**
     * @param int|string           $algorithm one of the `PASSWORD_*` constants (defaults to `PASSWORD_DEFAULT`)
     * @param array<string, mixed> $options   algorithm-specific options forwarded to `password_hash`
     */
    public function __construct(
        private readonly int|string $algorithm = \PASSWORD_DEFAULT,
        private readonly array $options = [],
    ) {}

    public function hash(string $plain): string
    {
        // On PHP 8+, password_hash throws ValueError on unsupported
        // algorithms and returns a non-empty string otherwise, so no
        // extra guarding is needed here.
        return \password_hash($plain, $this->algorithm, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        return \password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return \password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
