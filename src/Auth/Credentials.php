<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * What {@see UserProvider::findByIdentifier()} returns during the login
 * handshake: the principal that will be stored in the session, plus the
 * password hash to verify against.
 *
 * The password hash leaves this object only as input to
 * {@see PasswordHasher::verify()} — it must never be written to the
 * session or leaked to the caller.
 */
final readonly class Credentials
{
    public function __construct(
        public Identity $identity,
        public string $passwordHash,
    ) {}
}
