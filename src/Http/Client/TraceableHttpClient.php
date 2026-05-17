<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use Polidog\Relayer\Db\TraceableDatabase;
use Polidog\Relayer\Profiler\Profiler;
use Throwable;

/**
 * Dev-only {@see HttpClient} decorator that records every real request into
 * the request-scoped {@see Profiler}.
 *
 * Wired between {@see CachingHttpClient} (outer) and {@see CurlHttpClient}
 * (inner) in dev only, so it never sees a memoized call — every span it
 * emits is an actual network round-trip. Prod skips this class entirely
 * (the alias points straight at the cache over cURL).
 *
 * Recorded as a timed `request` span carrying method, URL, status and
 * response size. By design it does **not** record request headers or the
 * request/response bodies: that mirrors {@see TraceableDatabase},
 * which logs the SQL text but redacts the bound values — the URL/method are
 * the query's identity, while an `Authorization` header or a body could
 * carry a secret into the plain-JSON profile under `var/cache/profiler/`.
 * On failure the span is still stopped with an `error` before the
 * exception is rethrown, so a failing call is visible in the profile
 * rather than vanishing.
 */
final class TraceableHttpClient implements HttpClient
{
    public function __construct(
        private readonly HttpClient $inner,
        private readonly Profiler $profiler,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $span = $this->profiler->start('http', 'request');

        try {
            $response = $this->inner->request($method, $url, $headers, $body);
            $span->stop([
                'method' => \strtoupper($method),
                'url' => $url,
                'status' => $response->status,
                'bytes' => \strlen($response->body),
            ]);

            return $response;
        } catch (Throwable $e) {
            $span->stop([
                'method' => \strtoupper($method),
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }
}
