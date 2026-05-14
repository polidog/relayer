<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Reads `#[Cache]` from a class and emits matching HTTP headers, plus
 * implements RFC 7232 conditional GET (`If-None-Match`, `If-Modified-Since`)
 * for `304 Not Modified` short-circuiting.
 *
 * Header-writing methods skip when headers have already been sent, so they
 * stay safe to call from any point in the request lifecycle.
 */
final class CachePolicy
{
    /**
     * Convenience: read the attribute, resolve any dynamic `etagKey` via the
     * given store, and emit headers in one call. Returns the *effective* Cache
     * (with `etag` substituted from the store when applicable), or null if
     * the class has no `#[Cache]`.
     *
     * Does not short-circuit on 304 — the caller decides whether to call
     * `isNotModified()` + `sendNotModified()` and terminate the request.
     */
    public static function applyFromAttribute(string $class, ?EtagStore $store = null): ?Cache
    {
        $cache = self::extract($class);
        if ($cache === null) {
            return null;
        }

        $effective = self::resolveWithStore($cache, $store);
        self::emit($effective);

        return $effective;
    }

    /**
     * Substitute a dynamic ETag from the store when `etagKey` is set and no
     * static `etag` is configured. Returns the original Cache instance when
     * no substitution applies.
     */
    public static function resolveWithStore(Cache $cache, ?EtagStore $store): Cache
    {
        if ($cache->etag !== null || $cache->etagKey === null || $store === null) {
            return $cache;
        }

        $dynamic = $store->get($cache->etagKey);
        if ($dynamic === null || $dynamic === '') {
            return $cache;
        }

        return new Cache(
            maxAge: $cache->maxAge,
            sMaxAge: $cache->sMaxAge,
            public: $cache->public,
            private: $cache->private,
            noStore: $cache->noStore,
            noCache: $cache->noCache,
            mustRevalidate: $cache->mustRevalidate,
            immutable: $cache->immutable,
            vary: $cache->vary,
            etag: $dynamic,
            etagWeak: $cache->etagWeak,
            lastModified: $cache->lastModified,
            etagKey: $cache->etagKey,
        );
    }

    /**
     * Returns the `#[Cache]` instance attached to $class, or null if absent
     * (or class doesn't exist).
     */
    public static function extract(string $class): ?Cache
    {
        if (!\class_exists($class)) {
            return null;
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(Cache::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    public static function emit(Cache $cache): void
    {
        if (\headers_sent()) {
            return;
        }

        $directives = self::buildDirectives($cache);
        if ($directives !== []) {
            \header('Cache-Control: ' . \implode(', ', $directives));
        }

        if ($cache->vary !== []) {
            \header('Vary: ' . \implode(', ', $cache->vary));
        }

        if ($cache->etag !== null) {
            \header('ETag: ' . self::formatEtag($cache->etag, $cache->etagWeak));
        }

        $lastModified = self::parseLastModified($cache->lastModified);
        if ($lastModified !== null) {
            \header('Last-Modified: ' . \gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
    }

    /**
     * True if the current request's conditional headers indicate the client
     * already has a fresh copy. Only honors safe methods (GET, HEAD).
     */
    public static function isNotModified(Cache $cache): bool
    {
        $method = \strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if ($cache->etag !== null) {
            $ifNoneMatch = self::requestHeader('IF_NONE_MATCH');
            if ($ifNoneMatch !== null) {
                if ($ifNoneMatch === '*') {
                    return true;
                }
                if (self::etagMatchesAny(self::formatEtag($cache->etag, $cache->etagWeak), $ifNoneMatch)) {
                    return true;
                }
            }
        }

        $lastModified = self::parseLastModified($cache->lastModified);
        if ($lastModified !== null) {
            $ifModifiedSince = self::requestHeader('IF_MODIFIED_SINCE');
            if ($ifModifiedSince !== null) {
                $clientTime = \strtotime($ifModifiedSince);
                if ($clientTime !== false && $lastModified <= $clientTime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Send the `304 Not Modified` status. Callers should terminate the
     * request after this so no body is emitted.
     */
    public static function sendNotModified(): void
    {
        if (\headers_sent()) {
            return;
        }
        \http_response_code(304);
    }

    /**
     * Build the Cache-Control directive list.
     *
     * @return list<string>
     */
    public static function buildDirectives(Cache $cache): array
    {
        $directives = [];

        if ($cache->public) {
            $directives[] = 'public';
        } elseif ($cache->private) {
            $directives[] = 'private';
        }

        if ($cache->noStore) {
            $directives[] = 'no-store';
        }
        if ($cache->noCache) {
            $directives[] = 'no-cache';
        }
        if ($cache->maxAge !== null) {
            $directives[] = 'max-age=' . $cache->maxAge;
        }
        if ($cache->sMaxAge !== null) {
            $directives[] = 's-maxage=' . $cache->sMaxAge;
        }
        if ($cache->mustRevalidate) {
            $directives[] = 'must-revalidate';
        }
        if ($cache->immutable) {
            $directives[] = 'immutable';
        }

        return $directives;
    }

    /**
     * Wraps $value in quotes (if needed) and prefixes `W/` when $weak is true.
     */
    public static function formatEtag(string $value, bool $weak = false): string
    {
        $quoted = self::quoteEtag($value);

        if ($weak && !\str_starts_with($quoted, 'W/')) {
            return 'W/' . $quoted;
        }

        return $quoted;
    }

    /**
     * @return int|null Unix timestamp, or null when input is null/unparseable.
     */
    private static function parseLastModified(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $timestamp = \strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function quoteEtag(string $value): string
    {
        if (\str_starts_with($value, 'W/"') || \str_starts_with($value, '"')) {
            return $value;
        }

        return '"' . $value . '"';
    }

    /**
     * Weak comparison per RFC 7232 §2.3.2: strip `W/` from both sides and
     * compare the quoted opaque-tag. The client header may carry a list.
     */
    private static function etagMatchesAny(string $serverEtag, string $ifNoneMatch): bool
    {
        $serverBare = self::stripWeakPrefix($serverEtag);

        foreach (\explode(',', $ifNoneMatch) as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (self::stripWeakPrefix($candidate) === $serverBare) {
                return true;
            }
        }

        return false;
    }

    private static function stripWeakPrefix(string $etag): string
    {
        return \str_starts_with($etag, 'W/') ? \substr($etag, 2) : $etag;
    }

    private static function requestHeader(string $name): ?string
    {
        $key = 'HTTP_' . $name;
        $value = $_SERVER[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
