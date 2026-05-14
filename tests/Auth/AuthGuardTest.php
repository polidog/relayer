<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Auth;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\UserProvider;

final class AuthGuardTest extends TestCase
{
    public function testAllowsAuthenticatedRequest(): void
    {
        $auth = $this->loggedIn(['user']);
        $decision = AuthGuard::decide(new Auth(), $auth);

        self::assertSame(AuthGuard::DECISION_ALLOW, $decision);
    }

    public function testRedirectsAnonymousRequestByDefault(): void
    {
        $auth = $this->anonymous();
        $decision = AuthGuard::decide(new Auth(), $auth);

        self::assertSame(AuthGuard::DECISION_REDIRECT, $decision);
    }

    public function testReturnsUnauthorizedWhenRedirectIsEmpty(): void
    {
        $auth = $this->anonymous();
        $decision = AuthGuard::decide(new Auth(redirectTo: ''), $auth);

        self::assertSame(AuthGuard::DECISION_UNAUTHORIZED, $decision);
    }

    public function testForbidsAuthenticatedUserMissingRole(): void
    {
        $auth = $this->loggedIn(['user']);
        $decision = AuthGuard::decide(new Auth(roles: ['admin']), $auth);

        self::assertSame(AuthGuard::DECISION_FORBIDDEN, $decision);
    }

    public function testAllowsAuthenticatedUserWithMatchingRole(): void
    {
        $auth = $this->loggedIn(['user', 'admin']);
        $decision = AuthGuard::decide(new Auth(roles: ['admin']), $auth);

        self::assertSame(AuthGuard::DECISION_ALLOW, $decision);
    }

    public function testEmptyRolesMeansAnyAuthenticatedUser(): void
    {
        $auth = $this->loggedIn([]);
        $decision = AuthGuard::decide(new Auth(), $auth);

        self::assertSame(AuthGuard::DECISION_ALLOW, $decision);
    }

    /**
     * @param array<string> $roles
     */
    private function loggedIn(array $roles): Authenticator
    {
        $auth = $this->anonymous();
        $auth->login(new Identity(id: 1, displayName: 'Test', roles: $roles));

        return $auth;
    }

    private function anonymous(): Authenticator
    {
        $hasher = new NativePasswordHasher();
        $provider = new class implements UserProvider {
            public function findByIdentifier(string $identifier): ?Credentials
            {
                return null;
            }
        };

        return new Authenticator($provider, $hasher, new ArraySessionStorage());
    }
}
