<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Token\HttpJwksProvider;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Tests\Http\Client\FakeHttpClient;
use RuntimeException;

final class HttpJwksProviderTest extends TestCase
{
    private const URL = 'https://idp.test/.well-known/jwks.json';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/relayer-jwks-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->cacheDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->cacheDir);
    }

    public function testFetchesOnceThenServesFromCache(): void
    {
        $fixture = new JwtFixture('kid-a');
        $http = $this->http($fixture->jwksJson());
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir);

        $first = $provider->keys();
        $second = $provider->keys();

        self::assertArrayHasKey('kid-a', $first);
        self::assertArrayHasKey('kid-a', $second);
        self::assertSame(1, $http->requestCalls, 'second read is a cache hit');
    }

    public function testRefreshWithinCooldownDoesNotRefetch(): void
    {
        $fixture = new JwtFixture();
        $http = $this->http($fixture->jwksJson());
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir);

        $provider->keys();        // fetch #1
        $provider->refresh();     // inside the 60s cooldown -> no network

        self::assertSame(1, $http->requestCalls, 'a kid-miss flood cannot amplify into JWKS fetches');
    }

    public function testRefreshAfterCooldownRefetches(): void
    {
        $fixture = new JwtFixture();
        $http = $this->http($fixture->jwksJson());
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir, refreshCooldown: 0);

        $provider->keys();
        $provider->refresh();

        self::assertSame(2, $http->requestCalls);
    }

    public function testNonOkResponseThrowsAndIsNotSwallowed(): void
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(503, [], 'upstream down');
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir);

        $this->expectException(RuntimeException::class);
        $provider->keys();
    }

    public function testBodyWithoutKeysArrayThrows(): void
    {
        $http = $this->http('{"not":"a jwks"}');
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir);

        $this->expectException(RuntimeException::class);
        $provider->keys();
    }

    public function testStaleCacheTriggersRefetch(): void
    {
        $fixture = new JwtFixture();
        $http = $this->http($fixture->jwksJson());
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir);

        $provider->keys();
        $this->expireCache();
        $provider->keys();

        self::assertSame(2, $http->requestCalls, 'an expired cache entry is refetched');
    }

    public function testCacheControlMaxAgeDrivesExpiry(): void
    {
        $fixture = new JwtFixture();
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(200, ['Cache-Control' => 'public, max-age=1234'], $fixture->jwksJson());
        // minTtl/maxTtl chosen so 1234 is neither clamped.
        $provider = new HttpJwksProvider($http, self::URL, $this->cacheDir, minTtl: 1, maxTtl: 100000);

        $provider->keys();
        $cache = $this->readCache();

        self::assertSame(1234, $cache['expiresAt'] - $cache['fetchedAt']);
    }

    private function http(string $body): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(200, [], $body);

        return $http;
    }

    private function cachePath(): string
    {
        return $this->cacheDir . '/' . \sha1(self::URL) . '.json';
    }

    /**
     * @return array{fetchedAt: int, expiresAt: int}
     */
    private function readCache(): array
    {
        $data = $this->decodeCache();
        $fetchedAt = $data['fetchedAt'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        if (!\is_int($fetchedAt) || !\is_int($expiresAt)) {
            throw new RuntimeException('test JWKS cache file is malformed');
        }

        return ['fetchedAt' => $fetchedAt, 'expiresAt' => $expiresAt];
    }

    private function expireCache(): void
    {
        $cache = $this->readCache();
        $data = $this->decodeCache();
        $data['expiresAt'] = $cache['fetchedAt'] - 1;
        \file_put_contents($this->cachePath(), \json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeCache(): array
    {
        $raw = \file_get_contents($this->cachePath());
        if (!\is_string($raw)) {
            throw new RuntimeException('test JWKS cache file is missing');
        }
        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            throw new RuntimeException('test JWKS cache file is not a JSON object');
        }

        return $data;
    }
}
