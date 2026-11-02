<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use LogicException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Auth;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\Token\AuthorizationHeader;
use Polidog\Relayer\Auth\Token\TokenAuthenticator;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use WeakReference;

final class TokenAuthenticatorTest extends TestCase
{
    public function testNoHeaderIsUnauthenticatedAndSkipsVerification(): void
    {
        $verifier = new FakeTokenVerifier(new Identity('u', 'U'));
        $auth = new TokenAuthenticator($verifier, $this->header(null));

        self::assertFalse($auth->check());
        self::assertNull($auth->user());
        self::assertSame(0, $verifier->calls, 'no bearer token => verifier never invoked');
    }

    public function testNonBearerHeaderIsIgnored(): void
    {
        $verifier = new FakeTokenVerifier(new Identity('u', 'U'));
        $auth = new TokenAuthenticator($verifier, $this->header('Basic dXNlcjpwdw=='));

        self::assertFalse($auth->check());
        self::assertSame(0, $verifier->calls);
    }

    public function testValidTokenExposesPrincipal(): void
    {
        $identity = new Identity('u-7', 'Grace', ['admin', 'user']);
        $verifier = new FakeTokenVerifier($identity);
        $auth = new TokenAuthenticator($verifier, $this->header('Bearer the.jwt.value'));

        self::assertTrue($auth->check());
        self::assertSame($identity, $auth->user());
        self::assertSame('the.jwt.value', $verifier->lastToken, 'the parsed token reaches the verifier');
        self::assertTrue($auth->hasRole('admin'));
        self::assertFalse($auth->hasRole('root'));
        self::assertTrue($auth->hasAnyRole(['root', 'user']));
        self::assertTrue($auth->hasAnyRole([]), 'empty role list means any authenticated user');
    }

    public function testRejectedTokenIsUnauthenticated(): void
    {
        $verifier = new FakeTokenVerifier(null);
        $auth = new TokenAuthenticator($verifier, $this->header('Bearer bad'));

        self::assertFalse($auth->check());
        self::assertNull($auth->user());
        self::assertFalse($auth->hasAnyRole(['user']));
    }

    public function testVerificationIsMemoizedPerInstance(): void
    {
        $verifier = new FakeTokenVerifier(new Identity('u', 'U', ['user']));
        $auth = new TokenAuthenticator($verifier, $this->header('Bearer t'));

        $auth->check();
        $auth->user();
        $auth->hasRole('user');
        $auth->hasAnyRole(['user']);

        self::assertSame(1, $verifier->calls, 'token verified once for the whole request');
    }

    public function testAttemptIsUnsupported(): void
    {
        $auth = new TokenAuthenticator(new FakeTokenVerifier(), $this->header(null));

        $this->expectException(LogicException::class);
        $auth->attempt('a@example.com', 'pw');
    }

    public function testLoginIsUnsupported(): void
    {
        $auth = new TokenAuthenticator(new FakeTokenVerifier(), $this->header(null));

        $this->expectException(LogicException::class);
        $auth->login(new Identity('u', 'U'));
    }

    public function testLogoutIsUnsupported(): void
    {
        $auth = new TokenAuthenticator(new FakeTokenVerifier(), $this->header(null));

        $this->expectException(LogicException::class);
        $auth->logout();
    }

    public function testIntegratesWithAuthGuardDecisions(): void
    {
        $authed = new TokenAuthenticator(
            new FakeTokenVerifier(new Identity('u', 'U', ['editor'])),
            $this->header('Bearer t'),
        );
        $anon = new TokenAuthenticator(new FakeTokenVerifier(null), $this->header('Bearer t'));

        self::assertSame(
            AuthGuard::DECISION_ALLOW,
            AuthGuard::decide(new Auth(), $authed),
        );
        self::assertSame(
            AuthGuard::DECISION_FORBIDDEN,
            AuthGuard::decide(new Auth(roles: ['admin']), $authed),
            'authenticated but missing the required role',
        );
        self::assertSame(
            AuthGuard::DECISION_UNAUTHORIZED,
            AuthGuard::decide(new Auth(redirectTo: ''), $anon),
            'API-style attribute yields 401 for a bad/absent token',
        );
        self::assertSame(
            AuthGuard::DECISION_REDIRECT,
            AuthGuard::decide(new Auth(redirectTo: '/login'), $anon),
        );
    }

    public function testRequestResetForgetsThePrincipalOfEveryInstance(): void
    {
        // Two live instances (two containers, a non-shared service, ...):
        // the reset has to reach both, not only the newest one.
        $firstVerifier = new FakeTokenVerifier(new Identity('alice', 'Alice'));
        $first = new TokenAuthenticator($firstVerifier, $this->header('Bearer a'));
        $secondVerifier = new FakeTokenVerifier(new Identity('bob', 'Bob'));
        $second = new TokenAuthenticator($secondVerifier, $this->header('Bearer b'));

        $first->user();
        $second->user();

        RequestLifecycle::reset();

        // The next visitor's token does not verify: neither instance may
        // still answer with the previous request's principal.
        $firstVerifier->result = null;
        $secondVerifier->result = null;

        self::assertNull($first->user(), 'the first instance kept the previous user');
        self::assertNull($second->user());
        self::assertSame(2, $firstVerifier->calls);
        self::assertSame(2, $secondVerifier->calls);
    }

    public function testADiscardedInstanceIsNotKeptAliveByTheReset(): void
    {
        $auth = new TokenAuthenticator(new FakeTokenVerifier(), $this->header(null));
        $reference = WeakReference::create($auth);

        unset($auth);
        RequestLifecycle::reset();

        self::assertNull($reference->get());
    }

    private function header(?string $value): AuthorizationHeader
    {
        return new class($value) implements AuthorizationHeader {
            public function __construct(private readonly ?string $value) {}

            public function value(): ?string
            {
                return $this->value;
            }
        };
    }
}
