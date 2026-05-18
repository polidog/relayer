<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Token\Cognito;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Tests\Http\Client\FakeHttpClient;
use stdClass;

final class CognitoTest extends TestCase
{
    private const REGION = 'ap-northeast-1';
    private const POOL = 'ap-northeast-1_AbCdEf';
    private const CLIENT = 'app-client-1';
    private const ISSUER = 'https://cognito-idp.ap-northeast-1.amazonaws.com/ap-northeast-1_AbCdEf';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/relayer-jwks-cg-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->cacheDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->cacheDir);
    }

    public function testVerifiesACognitoIdTokenAndFetchesPoolJwks(): void
    {
        $fixture = new JwtFixture();
        $http = $this->httpServing($fixture->jwksJson());

        $verifier = Cognito::verifier($http, self::REGION, self::POOL, self::CLIENT, $this->cacheDir);

        $token = $fixture->sign($this->idClaims([
            'cognito:groups' => ['staff', 'admins'],
        ]));

        $identity = $verifier->verify($token);

        self::assertNotNull($identity);
        self::assertSame('cognito-sub-1', $identity->id);
        self::assertSame('Grace', $identity->displayName);
        self::assertSame(['staff', 'admins'], $identity->roles, 'roles come from cognito:groups');
        self::assertSame(
            self::ISSUER . '/.well-known/jwks.json',
            $http->lastUrl,
        );
    }

    public function testRejectsAnAccessTokenPresentedAsAnIdToken(): void
    {
        $fixture = new JwtFixture();
        $verifier = Cognito::verifier($this->httpServing($fixture->jwksJson()), self::REGION, self::POOL, self::CLIENT, $this->cacheDir);

        $token = $fixture->sign($this->idClaims(['token_use' => 'access']));

        self::assertNull($verifier->verify($token), 'token_use=access must not satisfy an ID-token check');
    }

    public function testRejectsWrongAppClientAudience(): void
    {
        $fixture = new JwtFixture();
        $verifier = Cognito::verifier($this->httpServing($fixture->jwksJson()), self::REGION, self::POOL, self::CLIENT, $this->cacheDir);

        $token = $fixture->sign($this->idClaims(['aud' => 'a-different-client']));

        self::assertNull($verifier->verify($token));
    }

    public function testDefaultMapperFallsBackNameThenUsernameThenEmailThenSub(): void
    {
        $map = Cognito::defaultIdentityMapper();

        $byUsername = $map((object) ['sub' => 's', 'cognito:username' => 'grace42']);
        self::assertNotNull($byUsername);
        self::assertSame('grace42', $byUsername->displayName);

        $byEmail = $map((object) ['sub' => 's', 'email' => 'g@example.com']);
        self::assertNotNull($byEmail);
        self::assertSame('g@example.com', $byEmail->displayName);

        self::assertNull($map(new stdClass()));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function idClaims(array $overrides = []): array
    {
        return [
            ...[
                'iss' => self::ISSUER,
                'aud' => self::CLIENT,
                'token_use' => 'id',
                'sub' => 'cognito-sub-1',
                'name' => 'Grace',
                'iat' => \time(),
                'exp' => \time() + 3600,
            ],
            ...$overrides,
        ];
    }

    private function httpServing(string $jwksJson): FakeHttpClient
    {
        $http = new FakeHttpClient();
        // Cognito's JWKS response carries no max-age; the provider's
        // default TTL applies. Header omitted on purpose.
        $http->response = new HttpResponse(200, [], $jwksJson);

        return $http;
    }
}
