<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Token\Firebase;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Tests\Http\Client\FakeHttpClient;
use stdClass;

final class FirebaseTest extends TestCase
{
    private const PROJECT_ID = 'demo-project';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/relayer-jwks-fb-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->cacheDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->cacheDir);
    }

    public function testVerifiesAFirebaseIdTokenAndFetchesTheWellKnownJwks(): void
    {
        $fixture = new JwtFixture();
        $http = $this->httpServing($fixture->jwksJson());

        $verifier = Firebase::verifier($http, self::PROJECT_ID, $this->cacheDir);

        $token = $fixture->sign([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'sub' => 'firebase-uid-1',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'roles' => ['admin'],
            'iat' => \time(),
            'exp' => \time() + 3600,
        ]);

        $identity = $verifier->verify($token);

        self::assertNotNull($identity);
        self::assertSame('firebase-uid-1', $identity->id);
        self::assertSame('Alice', $identity->displayName);
        self::assertSame(['admin'], $identity->roles);
        self::assertSame(Firebase::JWKS_URL, $http->lastUrl, 'fetched from Google secure-token JWKS');
    }

    public function testRejectsATokenFromAnotherProject(): void
    {
        $fixture = new JwtFixture();
        $verifier = Firebase::verifier($this->httpServing($fixture->jwksJson()), self::PROJECT_ID, $this->cacheDir);

        $token = $fixture->sign([
            'iss' => 'https://securetoken.google.com/some-other-project',
            'aud' => 'some-other-project',
            'sub' => 'x',
            'iat' => \time(),
            'exp' => \time() + 3600,
        ]);

        self::assertNull($verifier->verify($token));
    }

    public function testDefaultMapperFallsBackNameThenEmailThenSub(): void
    {
        $map = Firebase::defaultIdentityMapper();

        $byEmail = $map((object) ['sub' => 'uid', 'email' => 'a@example.com']);
        self::assertNotNull($byEmail);
        self::assertSame('a@example.com', $byEmail->displayName);

        $bySub = $map((object) ['sub' => 'uid-only']);
        self::assertNotNull($bySub);
        self::assertSame('uid-only', $bySub->displayName);

        self::assertNull($map(new stdClass()), 'a token without sub is not a principal');
    }

    private function httpServing(string $jwksJson): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(200, ['Cache-Control' => 'public, max-age=3600'], $jwksJson);

        return $http;
    }
}
