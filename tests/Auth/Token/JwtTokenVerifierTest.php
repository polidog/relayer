<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\Token\JwtTokenVerifier;
use RuntimeException;
use stdClass;

final class JwtTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://issuer.example.com/pool';
    private const AUDIENCE = 'client-123';

    public function testValidTokenResolvesIdentity(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $identity = $verifier->verify($fixture->sign($this->claims()));

        self::assertNotNull($identity);
        self::assertSame('user-1', $identity->id);
        self::assertSame('Alice', $identity->displayName);
        self::assertSame(['admin'], $identity->roles);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['exp' => \time() - 3600]));

        self::assertNull($verifier->verify($token));
    }

    public function testNotYetValidTokenIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['nbf' => \time() + 3600]));

        self::assertNull($verifier->verify($token));
    }

    public function testWrongIssuerIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['iss' => 'https://evil.example.com']));

        self::assertNull($verifier->verify($token));
    }

    public function testWrongAudienceIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['aud' => 'someone-else']));

        self::assertNull($verifier->verify($token));
    }

    public function testAudienceArrayContainingExpectedIsAccepted(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['aud' => ['other', self::AUDIENCE]]));

        self::assertNotNull($verifier->verify($token));
    }

    public function testAudienceArrayWithoutExpectedIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        $token = $fixture->sign($this->claims(['aud' => ['other', 'nope']]));

        self::assertNull($verifier->verify($token));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $real = new JwtFixture('shared-kid');
        $forger = new JwtFixture('shared-kid');
        // Provider only trusts the real key; the token is signed by a
        // different key advertising the same kid.
        $verifier = $this->verifier(new ArrayJwksProvider($real->keySet()));

        $token = $forger->sign($this->claims());

        self::assertNull($verifier->verify($token));
    }

    public function testRequiredClaimMismatchIsRejected(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(
            new ArrayJwksProvider($fixture->keySet()),
            requiredClaims: ['token_use' => 'id'],
        );

        $token = $fixture->sign($this->claims(['token_use' => 'access']));

        self::assertNull($verifier->verify($token));
    }

    public function testRequiredClaimMatchIsAccepted(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(
            new ArrayJwksProvider($fixture->keySet()),
            requiredClaims: ['token_use' => 'id'],
        );

        $token = $fixture->sign($this->claims(['token_use' => 'id']));

        self::assertNotNull($verifier->verify($token));
    }

    public function testUnknownKidTriggersSingleRefreshThenSucceeds(): void
    {
        $cached = new JwtFixture('old-key');
        $rotated = new JwtFixture('new-key');
        $provider = new ArrayJwksProvider($cached->keySet());
        $provider->rotateOnRefresh($rotated->keySet());

        $verifier = $this->verifier($provider);
        $token = $rotated->sign($this->claims());

        $identity = $verifier->verify($token);

        self::assertNotNull($identity, 'rotated signing key is picked up via refresh');
        self::assertSame(1, $provider->refreshCalls, 'exactly one refresh on kid miss');
    }

    public function testUnknownKidStillUnknownAfterRefreshReturnsNull(): void
    {
        $cached = new JwtFixture('old-key');
        $other = new JwtFixture('ghost-key');
        $provider = new ArrayJwksProvider($cached->keySet()); // no rotateOnRefresh

        $verifier = $this->verifier($provider);
        $token = $other->sign($this->claims());

        self::assertNull($verifier->verify($token));
        self::assertSame(1, $provider->refreshCalls, 'refresh attempted once, not retried in a loop');
    }

    public function testInfrastructureFailurePropagatesAndIsNotSwallowed(): void
    {
        $fixture = new JwtFixture();
        $provider = new ArrayJwksProvider($fixture->keySet());
        $provider->throwOnKeys = new RuntimeException('JWKS endpoint unreachable');

        $verifier = $this->verifier($provider);

        // A transport fault is an operational error, not "not
        // authenticated" — it must surface, never resolve to null.
        $this->expectException(RuntimeException::class);
        $verifier->verify($fixture->sign($this->claims()));
    }

    public function testMapperReturningNullYieldsNull(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        // Signature + iss/aud are fine, but the mapper rejects a token
        // whose `sub` is missing.
        $claims = $this->claims();
        unset($claims['sub']);

        self::assertNull($verifier->verify($fixture->sign($claims)));
    }

    public function testMalformedTokenReturnsNull(): void
    {
        $fixture = new JwtFixture();
        $verifier = $this->verifier(new ArrayJwksProvider($fixture->keySet()));

        self::assertNull($verifier->verify('not-a-jwt'));
        self::assertNull($verifier->verify('a.b.c'));
    }

    /**
     * @param array<string, string> $requiredClaims
     */
    private function verifier(ArrayJwksProvider $provider, array $requiredClaims = []): JwtTokenVerifier
    {
        return new JwtTokenVerifier(
            $provider,
            self::ISSUER,
            self::AUDIENCE,
            $this->mapper(),
            $requiredClaims,
            leeway: 30,
        );
    }

    /**
     * @return Closure(stdClass): ?Identity
     */
    private function mapper(): Closure
    {
        return static function (stdClass $claims): ?Identity {
            $sub = $claims->sub ?? null;
            if (!\is_string($sub) || '' === $sub) {
                return null;
            }
            $name = $claims->name ?? null;

            $roles = [];
            $rawRoles = $claims->roles ?? null;
            if (\is_array($rawRoles)) {
                foreach ($rawRoles as $role) {
                    if (\is_string($role)) {
                        $roles[] = $role;
                    }
                }
            }

            return new Identity(
                id: $sub,
                displayName: \is_string($name) ? $name : $sub,
                roles: $roles,
            );
        };
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            ...[
                'iss' => self::ISSUER,
                'aud' => self::AUDIENCE,
                'sub' => 'user-1',
                'name' => 'Alice',
                'roles' => ['admin'],
                'iat' => \time(),
                'exp' => \time() + 3600,
            ],
            ...$overrides,
        ];
    }
}
