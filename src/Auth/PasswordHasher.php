<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Hash and verify passwords for {@see Authenticator}.
 *
 * The default implementation ({@see NativePasswordHasher}) uses PHP's
 * native `password_hash` with argon2id. Apps that need a specific
 * algorithm, cost, or peppering can register a custom service for this
 * interface.
 */
interface PasswordHasher
{
    public function hash(string $plain): string;

    public function verify(string $plain, string $hash): bool;

    /**
     * True when the stored hash should be rehashed (e.g. algorithm or
     * cost changed since the hash was created). Apps typically rehash
     * after a successful login and write the new hash back to storage.
     */
    public function needsRehash(string $hash): bool;
}
