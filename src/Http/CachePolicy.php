<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Polidog\Rosanna\Runtime\RequestLifecycle;
use ReflectionClass;

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
    /** What a policy that would let a shared cache store the response is replaced with. */
    private const string NOT_SHARED = 'Cache-Control: private, no-store';

    private static ?string $limiterBeforePolicy = null;

    /** A policy a shared cache may store went out this request; see {@see endRequest()}. */
    private static bool $sharedPolicyPending = false;

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
        if (null === $cache) {
            return null;
        }

        return self::applyCache($cache, $store);
    }

    /**
     * Resolve any dynamic `etagKey` and emit the cache headers. Returns the
     * effective Cache so the caller can pass it to `isNotModified()`.
     *
     * Used by both the `#[Cache]` attribute path (class-style pages) and the
     * `PageContext::cache()` path (function-style pages).
     */
    public static function applyCache(Cache $cache, ?EtagStore $store = null): Cache
    {
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
        if (null !== $cache->etag || null === $cache->etagKey || null === $store) {
            return $cache;
        }

        $dynamic = $store->get($cache->etagKey);
        if (null === $dynamic || '' === $dynamic) {
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

        $attributes = (new ReflectionClass($class))->getAttributes(Cache::class);
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    public static function emit(Cache $cache): void
    {
        if (\headers_sent()) {
            return;
        }

        self::reconcileWithSession($cache);

        $directives = self::buildDirectives($cache);
        if ([] !== $directives) {
            \header('Cache-Control: ' . \implode(', ', $directives));
        }

        if ([] !== $cache->vary) {
            \header('Vary: ' . \implode(', ', $cache->vary));
        }

        if (null !== $cache->etag) {
            \header('ETag: ' . self::formatEtag($cache->etag, $cache->etagWeak));
        }

        $lastModified = self::parseLastModified($cache->lastModified);
        if (null !== $lastModified) {
            \header('Last-Modified: ' . \gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }

        if (self::carriesCredentials(Request::fromGlobals())) {
            self::withdrawFromSharedCaches($cache);
        }
    }

    /**
     * Undo the limiter change from an earlier request. A FrankenPHP worker
     * keeps ini changes between requests, so a page declaring a policy would
     * otherwise strip PHP's `no-store` from later session pages that declare
     * none. A no-op in the classic per-request model.
     *
     * Called at the top of `AppRouter::run()`, so it also makes sure this
     * request ends with {@see endRequest()}.
     */
    public static function restoreSessionCacheLimiter(): void
    {
        self::registerEndRequest();

        if (null === self::$limiterBeforePolicy
            || \PHP_SESSION_NONE !== \session_status()
            || \headers_sent()
        ) {
            return;
        }

        \session_cache_limiter(self::$limiterBeforePolicy);
        self::$limiterBeforePolicy = null;
    }

    /**
     * True if the current request's conditional headers indicate the client
     * already has a fresh copy. Only honors safe methods (GET, HEAD).
     *
     * Per RFC 7232 §3.3, `If-Modified-Since` is ignored whenever
     * `If-None-Match` is present. Without a server ETag there is nothing to
     * confirm the client's validator against, so such a request never matches.
     *
     * A request carrying a session cookie or credentials always renders, and
     * so does one that opened a session (a middleware that logged the visitor
     * in from a remember-me cookie, with no session cookie sent yet), even if
     * it already closed it again. The validator says nothing
     * about who is asking, so a browser that cached the page anonymously and
     * then logged in would otherwise be told to keep that copy.
     *
     * Credentials on $request also withdraw a shared-cacheable policy, as
     * {@see emit()} does for the ones it finds in the globals: a middleware may
     * have added them with {@see Request::withHeader()}, which only the
     * routed request carries.
     */
    public static function isNotModified(Cache $cache, ?Request $request = null): bool
    {
        $request ??= Request::fromGlobals();
        if (self::carriesCredentials($request)) {
            self::withdrawFromSharedCaches($cache);
        }

        if (!$request->isGet() && !$request->isMethod('HEAD')) {
            return false;
        }

        if (self::identifiesAClient($request)) {
            return false;
        }

        $ifNoneMatch = self::requestHeader($request, 'if-none-match');
        if (null !== $ifNoneMatch) {
            if (null === $cache->etag) {
                return false;
            }

            return '*' === $ifNoneMatch
                || self::etagMatchesAny(self::formatEtag($cache->etag, $cache->etagWeak), $ifNoneMatch);
        }

        $lastModified = self::parseLastModified($cache->lastModified);
        if (null !== $lastModified) {
            $ifModifiedSince = self::requestHeader($request, 'if-modified-since');
            if (null !== $ifModifiedSince) {
                $clientTime = \strtotime($ifModifiedSince);
                if (false !== $clientTime && $lastModified <= $clientTime) {
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
        if (null !== $cache->maxAge) {
            $directives[] = 'max-age=' . $cache->maxAge;
        }
        if (null !== $cache->sMaxAge) {
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

    private static function identifiesAClient(Request $request): bool
    {
        $sessionName = \session_name();

        return (false !== $sessionName && null !== $request->cookie($sessionName))
            || self::sessionInUse()
            || self::carriesCredentials($request);
    }

    /**
     * True if this request has opened a session: one that is active now, or
     * one it started or resumed and already closed (`session_write_close()`
     * before `$next()`, to release the lock), whoever called
     * `session_start()`.
     *
     * A closed session is no longer visible in `session_status()`, and one
     * resumed from the visitor's cookie queues no `Set-Cookie` either. What it
     * does leave is `$_SESSION`: `session_start()` defines it and nothing
     * before the end of the request takes it away again (closing, aborting
     * and destroying all keep it), while a request that never opens a session
     * has none, even if it carries a session cookie. A worker keeps the
     * superglobal between requests, so it is dropped when the request ends
     * ({@see endRequest()}).
     */
    private static function sessionInUse(): bool
    {
        self::registerEndRequest();

        return \PHP_SESSION_ACTIVE === \session_status()
            || isset($_SESSION)
            || self::sessionCookieQueued();
    }

    /**
     * Registered under one name, so it is one entry however often this runs.
     */
    private static function registerEndRequest(): void
    {
        RequestLifecycle::onReset(self::class, self::endRequest(...));
    }

    /**
     * Run by {@see RequestLifecycle::reset()} at the end of `AppRouter::run()`.
     *
     * A closed session's `$_SESSION` is dropped, which PHP itself only does
     * when the process's request ends: in a worker the next visitor would
     * otherwise count as having used a session, and could read the previous
     * visitor's data from it. An active session keeps it, as PHP still has to
     * write it.
     *
     * Headers usually go out only after this (`output_buffering` holds a
     * small response until the script ends), so the withdrawal
     * {@see reconcileWithSession()} leaves to its header callback is decided
     * here first, while `$_SESSION` still shows the session. Everything has
     * rendered by now; the callback still catches a session opened later.
     */
    private static function endRequest(): void
    {
        if (self::$sharedPolicyPending && !\headers_sent() && self::sessionInUse()) {
            \header(self::NOT_SHARED);
        }
        self::$sharedPolicyPending = false;

        if (\PHP_SESSION_ACTIVE !== \session_status()) {
            unset($_SESSION);
        }
    }

    private static function sessionCookieQueued(): bool
    {
        $name = \session_name();
        if (false === $name) {
            return false;
        }

        foreach (\headers_list() as $header) {
            if (0 === \strncasecmp($header, 'Set-Cookie:', 11)
                && \str_starts_with(\ltrim(\substr($header, 11)), $name . '=')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only `Authorization` counts. A session cookie alone does not: a page
     * that never touches the session renders the same for everyone, and one
     * that does is caught when it opens the session
     * ({@see reconcileWithSession()}).
     */
    private static function carriesCredentials(Request $request): bool
    {
        return null !== self::requestHeader($request, 'authorization');
    }

    /**
     * A response rendered for credentials may be personal, so a policy that
     * lets a shared cache store it is replaced; `private` / `noStore` already
     * keep it out of one and stay as declared.
     */
    private static function withdrawFromSharedCaches(Cache $cache): void
    {
        if (\headers_sent() || !self::allowsSharedStorage($cache)) {
            return;
        }

        \header(self::NOT_SHARED);
    }

    private static function allowsSharedStorage(Cache $cache): bool
    {
        return !$cache->noStore && !($cache->private && !$cache->public);
    }

    /**
     * A declared policy and a PHP session disagree in both directions.
     *
     * PHP's default `session.cache_limiter` makes `session_start()` overwrite
     * `Cache-Control` with `no-store`, even on a page that only happens to
     * touch the session. The limiter is therefore switched off once a policy
     * is declared; pages without one keep PHP's `no-store`.
     *
     * With the limiter off, nothing would stop a response that used a session
     * from going out as declared, and a CDN could then serve one visitor's
     * rendering (or their `Set-Cookie`) to everyone. A policy that allows
     * shared storage is therefore withdrawn at the last moment if the request
     * has opened a session by then ({@see sessionInUse()}), including one a
     * middleware started or resumed and closed before the page ran. It has to
     * be that late: layouts render after this runs, and the session may be
     * started by code the framework does not own. "The last moment" is the end
     * of the request ({@see endRequest()}) or the header callback, whichever
     * comes first. A `private` / `no-store` policy is already safe and stays
     * as declared.
     */
    private static function reconcileWithSession(Cache $cache): void
    {
        if (!\extension_loaded('session')) {
            return;
        }

        if (\PHP_SESSION_NONE === \session_status()) {
            $previous = \session_cache_limiter('');
            self::$limiterBeforePolicy ??= false === $previous ? null : $previous;
        }

        if ([] !== self::buildDirectives($cache) && self::sessionInUse()) {
            // Started before this ran: the limiter's leftovers would contradict
            // the Cache-Control about to replace its own.
            \header_remove('Expires');
            \header_remove('Pragma');
        }

        if (!self::allowsSharedStorage($cache)) {
            return;
        }

        self::$sharedPolicyPending = true;
        \header_register_callback(static function (): void {
            if (self::sessionInUse()) {
                \header(self::NOT_SHARED);
            }
        });
    }

    /**
     * @return null|int unix timestamp, or null when input is null/unparseable
     */
    private static function parseLastModified(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }

        $timestamp = \strtotime($value);

        return false === $timestamp ? null : $timestamp;
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
            if ('' === $candidate) {
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

    private static function requestHeader(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return null !== $value && '' !== $value ? $value : null;
    }
}
