<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use Polidog\Relayer\Db\CachingDatabase;
use Polidog\Relayer\Profiler\Profiler;

/**
 * Request-scoped memoization for {@see HttpClient}.
 *
 * The same logical page is often assembled from several components that
 * each call the same external endpoint (a config service, the current
 * user's profile from an identity API, …). Without memoization that is N
 * identical round-trips per request. This decorator caches responses keyed
 * by `(method, url, headers, body)` for the lifetime of the request only — a
 * plain in-process array, never persisted, dead with the worker. There is
 * no TTL and no cross-request sharing by design; HTTP caching with
 * freshness/revalidation is a different, much larger problem this layer
 * deliberately does not solve.
 *
 * Only safe methods (`GET`, `HEAD`) are memoized. Any other method
 * (`POST`/`PUT`/`PATCH`/`DELETE`/…) is sent every time and clears the whole
 * cache first — a request that reads, writes, then reads again must see
 * its own write, and a blunt full flush is the same simple, safe choice
 * {@see CachingDatabase} makes for `perform()`. The
 * flush is intentionally client-wide (not per-host): it mirrors the DB
 * decorator exactly and keeps this layer free of host-tracking machinery.
 *
 * This is the OUTERMOST decorator (it wraps {@see TraceableHttpClient} in
 * dev, {@see CurlHttpClient} directly in prod). So a cache hit
 * short-circuits before the traceable layer: the hit is recorded once as
 * an `http.cache_hit` event and produces no `http.request` span, keeping
 * the profiler timeline showing real round-trips as spans and saved ones
 * as hit markers.
 */
final class CachingHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse> */
    private array $cache = [];

    public function __construct(
        private readonly HttpClient $inner,
        private readonly Profiler $profiler,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $method = \strtoupper($method);

        if ('GET' !== $method && 'HEAD' !== $method) {
            $this->cache = [];

            return $this->inner->request($method, $url, $headers, $body);
        }

        $key = $method . '|' . $url . '|' . \serialize($headers) . '|' . \serialize($body);

        if (\array_key_exists($key, $this->cache)) {
            $this->profiler->collect('http', 'cache_hit', ['method' => $method, 'url' => $url]);

            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->inner->request($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }
}
