<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\Token\TokenAuthenticator;
use Polidog\Relayer\Auth\Token\TokenVerifier;

/**
 * Scriptable {@see TokenVerifier} double: returns a preset result and
 * counts calls so {@see TokenAuthenticator}'s
 * per-request memoization can be asserted.
 *
 * Not named `*Test`, so PHPUnit skips it; PSR-4 autoload still loads it.
 */
final class FakeTokenVerifier implements TokenVerifier
{
    public int $calls = 0;

    public ?string $lastToken = null;

    public function __construct(public ?Identity $result = null) {}

    public function verify(string $token): ?Identity
    {
        ++$this->calls;
        $this->lastToken = $token;

        return $this->result;
    }
}
