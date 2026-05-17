<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use Polidog\Relayer\Db\Database;
use Polidog\Relayer\Db\DatabaseException;
use Polidog\Relayer\Profiler\Profiler;

/**
 * Minimal outbound HTTP contract: a method + URL in, an {@see HttpResponse}
 * out. The mirror of {@see Database} for talking to
 * external Web APIs instead of a SQL database.
 *
 * Bound in DI as `HttpClient::class`. The concrete implementation is
 * {@see CurlHttpClient}; it is always wrapped by {@see CachingHttpClient}
 * (request-scoped memoization of safe requests) and, in `dev`, additionally
 * by {@see TraceableHttpClient} so every real call lands in the request
 * {@see Profiler}.
 *
 * Page/component code takes an `HttpClient` dependency and calls an API
 * directly. There is no client builder, no middleware stack and no PSR-18
 * indirection on purpose — keep it thin; add layers above this contract
 * only when a concrete need appears (the same stance {@see Database}
 * takes on query builders).
 *
 * A 4xx/5xx is **not** an error here: it is a valid {@see HttpResponse} the
 * caller inspects via `$response->status` (the same way a SELECT returning
 * zero rows is not a {@see DatabaseException}). Only a
 * transport failure — DNS, connect, TLS, timeout, a body that never
 * arrives — throws {@see HttpClientException}, with the underlying driver
 * error preserved as the previous exception.
 */
interface HttpClient
{
    /**
     * Issue a request and return the response.
     *
     * Only safe methods (`GET`, `HEAD`) are memoized by
     * {@see CachingHttpClient}; any other method is sent every time and
     * additionally flushes the request-scoped cache, so a request that
     * reads, writes, then reads again sees its own write.
     *
     * @param string                $method  HTTP method, e.g. `GET`, `POST`
     * @param string                $url     absolute URL including scheme
     * @param array<string, string> $headers header name => value
     * @param null|string           $body    raw request body, or null for none
     *
     * @throws HttpClientException on transport failure (never on a 4xx/5xx)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;

    /**
     * Convenience for the common `GET` path. Equivalent to
     * `request('GET', $url, $headers)` and memoized the same way.
     *
     * @param array<string, string> $headers header name => value
     *
     * @throws HttpClientException on transport failure (never on a 4xx/5xx)
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
