<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Http\FileEtagStore;
use RuntimeException;

/**
 * {@see JwksProvider} backed by the framework's own {@see HttpClient} and a
 * one-file-per-URL filesystem cache.
 *
 * Why not `firebase/php-jwt`'s own `CachedKeySet`? It requires a PSR-18
 * client, a PSR-17 factory and a PSR-6 pool — pulling Guzzle and a second
 * HTTP egress path into a framework that deliberately owns a single thin
 * {@see HttpClient} (so every outbound call lands in the profiler in dev).
 * Reusing our client keeps that invariant; the cache is a small atomic
 * file mirroring {@see FileEtagStore}.
 *
 * Freshness comes from the JWKS response's `Cache-Control: max-age`
 * (Google sends a multi-hour value; Cognito sends none, so a default
 * applies), clamped to `[$minTtl, $maxTtl]`. Key rotation is handled out
 * of band: when a token's `kid` is missing the verifier calls
 * {@see refresh()}, which re-fetches — but no more than once per
 * `$refreshCooldown` seconds, so a stream of forged tokens with bogus
 * `kid`s cannot be amplified into a stream of JWKS fetches.
 *
 * The cache stores the decoded JWKS array (not {@see Key} objects, which
 * do not round-trip through serialization); {@see JWK::parseKeySet()} is
 * pure CPU and re-run per call.
 */
final class HttpJwksProvider implements JwksProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $jwksUrl,
        private readonly string $cacheDir,
        private readonly string $defaultAlg = 'RS256',
        private readonly int $minTtl = 300,
        private readonly int $maxTtl = 86400,
        private readonly int $defaultTtl = 3600,
        private readonly int $refreshCooldown = 60,
    ) {}

    public function keys(): array
    {
        $cached = $this->readCache();
        if (null !== $cached && \time() < $cached['expiresAt']) {
            return $this->parse($cached['jwks']);
        }

        return $this->fetchAndCache();
    }

    public function refresh(): array
    {
        // Bound how often a forced refresh actually leaves the box: within
        // the cooldown of the last successful fetch we reuse what we have,
        // so an attacker spraying tokens with unknown `kid`s cannot turn
        // each one into an outbound JWKS request.
        $cached = $this->readCache();
        if (null !== $cached && \time() - $cached['fetchedAt'] < $this->refreshCooldown) {
            return $this->parse($cached['jwks']);
        }

        return $this->fetchAndCache();
    }

    /**
     * @return array<string, Key>
     */
    private function fetchAndCache(): array
    {
        $response = $this->http->get($this->jwksUrl);
        if (!$response->ok()) {
            throw new RuntimeException(\sprintf(
                'JWKS endpoint %s returned HTTP %d',
                $this->jwksUrl,
                $response->status,
            ));
        }

        $decoded = $response->json();
        if (!\is_array($decoded) || !isset($decoded['keys']) || !\is_array($decoded['keys'])) {
            throw new RuntimeException(\sprintf('JWKS endpoint %s returned a body without a "keys" array', $this->jwksUrl));
        }

        // Parse before caching so a structurally invalid key set fails
        // loudly here rather than poisoning the cache.
        $keys = $this->parse($decoded);

        $now = \time();
        $this->writeCache([
            'fetchedAt' => $now,
            'expiresAt' => $now + $this->ttlFrom($response->header('Cache-Control')),
            'jwks' => $decoded,
        ]);

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $jwks decoded JWKS document
     *
     * @return array<string, Key>
     */
    private function parse(array $jwks): array
    {
        return JWK::parseKeySet($jwks, $this->defaultAlg);
    }

    private function ttlFrom(?string $cacheControl): int
    {
        $ttl = $this->defaultTtl;
        if (null !== $cacheControl && 1 === \preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $m)) {
            $ttl = (int) $m[1];
        }

        return \max($this->minTtl, \min($this->maxTtl, $ttl));
    }

    /**
     * @return null|array{fetchedAt: int, expiresAt: int, jwks: array<array-key, mixed>}
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return null;
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return null;
        }

        $fetchedAt = $data['fetchedAt'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        $jwks = $data['jwks'] ?? null;
        if (!\is_int($fetchedAt) || !\is_int($expiresAt) || !\is_array($jwks)) {
            // A corrupt or schema-drifted cache file is treated as a miss,
            // not a hard error: the next fetch overwrites it. Rebuilt as
            // a typed array so the shape is inferred, not asserted.
            return null;
        }

        return ['fetchedAt' => $fetchedAt, 'expiresAt' => $expiresAt, 'jwks' => $jwks];
    }

    /**
     * @param array{fetchedAt: int, expiresAt: int, jwks: array<array-key, mixed>} $data
     */
    private function writeCache(array $data): void
    {
        $this->ensureDirectory();

        $path = $this->cachePath();
        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        $json = \json_encode($data, \JSON_THROW_ON_ERROR);

        if (false === @\file_put_contents($tmp, $json, \LOCK_EX)) {
            throw new RuntimeException("Failed to write JWKS cache file: {$tmp}");
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            throw new RuntimeException("Failed to publish JWKS cache file: {$path}");
        }
    }

    private function cachePath(): string
    {
        return $this->cacheDir . '/' . \sha1($this->jwksUrl) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (\is_dir($this->cacheDir)) {
            return;
        }
        if (!@\mkdir($this->cacheDir, 0o755, true) && !\is_dir($this->cacheDir)) {
            throw new RuntimeException("Failed to create JWKS cache directory: {$this->cacheDir}");
        }
    }
}
