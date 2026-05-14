<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\UserProvider;

final class AuthenticatorTest extends TestCase
{
    public function testAttemptStoresIdentityOnSuccess(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        $identity = $auth->attempt('alice@example.com', 'secret123');

        self::assertNotNull($identity);
        self::assertSame('Alice', $identity->displayName);
        self::assertTrue($auth->check());
        self::assertSame(1, $session->regenerateCount, 'login() must rotate the session id');
        self::assertArrayHasKey('relayer.auth.identity', $session->data);
    }

    public function testAttemptReturnsNullForWrongPassword(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        self::assertNull($auth->attempt('alice@example.com', 'WRONG'));
        self::assertFalse($auth->check());
        self::assertSame(0, $session->regenerateCount);
    }

    public function testAttemptReturnsNullForUnknownUser(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        self::assertNull($auth->attempt('nobody@example.com', 'anything'));
        self::assertFalse($auth->check());
    }

    public function testLogoutClearsSessionAndRotatesId(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        $auth->attempt('alice@example.com', 'secret123');
        self::assertTrue($auth->check());

        $auth->logout();

        self::assertFalse($auth->check());
        self::assertSame(2, $session->regenerateCount, 'login and logout each rotate');
        self::assertArrayNotHasKey('relayer.auth.identity', $session->data);
    }

    public function testUserHydratesFromSessionAcrossInstances(): void
    {
        // Simulates the second request: a fresh Authenticator backed by
        // session storage that already contains the identity from a
        // previous login.
        $session = new ArraySessionStorage();
        $first = $this->makeAuthenticator($session);
        $first->attempt('alice@example.com', 'secret123');

        $second = $this->makeAuthenticator($session);
        $user = $second->user();

        self::assertNotNull($user);
        self::assertSame('Alice', $user->displayName);
        self::assertSame(['user'], $user->roles);
    }

    public function testHasAnyRole(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        self::assertFalse($auth->hasAnyRole(['user']));

        $auth->attempt('alice@example.com', 'secret123');

        self::assertTrue($auth->hasAnyRole(['user']));
        self::assertTrue($auth->hasAnyRole(['admin', 'user']));
        self::assertFalse($auth->hasAnyRole(['admin']));
        self::assertTrue($auth->hasAnyRole([]), 'empty role list means "any authenticated user"');
    }

    public function testLoginAcceptsArbitraryIdentity(): void
    {
        $session = new ArraySessionStorage();
        $auth = $this->makeAuthenticator($session);

        $auth->login(new Identity(id: 'sso-42', displayName: 'OAuth User', roles: ['user']));

        self::assertTrue($auth->check());
        self::assertSame('sso-42', $auth->user()?->id);
    }

    private function makeAuthenticator(ArraySessionStorage $session): Authenticator
    {
        $hasher = new NativePasswordHasher();
        $provider = new class($hasher) implements UserProvider {
            private readonly string $aliceHash;

            public function __construct(NativePasswordHasher $hasher)
            {
                $this->aliceHash = $hasher->hash('secret123');
            }

            public function findByIdentifier(string $identifier): ?Credentials
            {
                if ('alice@example.com' !== $identifier) {
                    return null;
                }

                return new Credentials(
                    identity: new Identity(id: 1, displayName: 'Alice', roles: ['user']),
                    passwordHash: $this->aliceHash,
                );
            }
        };

        return new Authenticator($provider, $hasher, $session);
    }
}
