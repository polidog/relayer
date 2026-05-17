<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use CurlHandle;
use Polidog\Relayer\Db\PdoDatabase;

/**
 * {@see HttpClient} on top of ext-curl.
 *
 * The mirror of {@see PdoDatabase}: a thin adapter over
 * a ubiquitous extension, configured to fail loudly and predictably. A
 * connect timeout and a total timeout are applied when configured so a
 * stuck endpoint surfaces as an {@see HttpClientException} instead of
 * hanging the worker until the PHP `max_execution_time`.
 *
 * A 4xx/5xx is returned as a normal {@see HttpResponse} — only a
 * transport-level cURL failure (DNS, connect, TLS, timeout, truncated
 * body) is caught and rethrown as {@see HttpClientException}, with the
 * cURL error message kept in the exception message.
 *
 * Redirects are intentionally **not** followed: the 3xx is returned as-is
 * so behavior stays explicit (no implicit cross-host hops, no silently
 * leaked `Authorization` header). A caller that wants to follow one reads
 * `Location` and issues the next request itself.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param null|int $timeout        seconds for the whole transfer
     *                                 (`CURLOPT_TIMEOUT`); null leaves cURL's
     *                                 default (no limit)
     * @param null|int $connectTimeout seconds for connect/handshake only
     *                                 (`CURLOPT_CONNECTTIMEOUT`)
     */
    public function __construct(
        private readonly ?int $timeout = null,
        private readonly ?int $connectTimeout = null,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $method = \strtoupper($method);
        $handle = \curl_init();

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        \curl_setopt_array($handle, $this->options($method, $url, $headers, $body, $responseHeaders));

        $result = \curl_exec($handle);

        if (false === $result) {
            $error = \curl_error($handle);
            $errno = \curl_errno($handle);
            \curl_close($handle);

            throw new HttpClientException(
                \sprintf('HTTP request to %s failed: %s (cURL %d)', $url, $error, $errno),
            );
        }

        $status = (int) \curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        return new HttpResponse($status, $responseHeaders, \is_string($result) ? $result : '');
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * Build the cURL option map for one request.
     *
     * `HEAD` uses `CURLOPT_NOBODY` (setting it as a custom request makes
     * cURL wait for a body that never comes); `GET` is the default verb;
     * everything else goes through `CURLOPT_CUSTOMREQUEST` with the body, if
     * any, as raw `CURLOPT_POSTFIELDS`. Response headers are collected by a
     * callback rather than `CURLOPT_HEADER` so they never end up prepended
     * to the body, and so a redirect's intermediate headers are dropped in
     * favor of the final response's.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $responseHeaders collected by reference
     *
     * @return array<int, mixed>
     */
    private function options(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        array &$responseHeaders,
    ): array {
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            \CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$responseHeaders): int {
                $parts = \explode(':', $line, 2);
                if (2 === \count($parts)) {
                    $responseHeaders[\trim($parts[0])] = \trim($parts[1]);
                }

                return \strlen($line);
            },
        ];

        if ('HEAD' === $method) {
            $options[\CURLOPT_NOBODY] = true;
        } elseif ('GET' !== $method) {
            $options[\CURLOPT_CUSTOMREQUEST] = $method;
            if (null !== $body) {
                $options[\CURLOPT_POSTFIELDS] = $body;
            }
        }

        if (null !== $this->connectTimeout) {
            $options[\CURLOPT_CONNECTTIMEOUT] = $this->connectTimeout;
        }
        if (null !== $this->timeout) {
            $options[\CURLOPT_TIMEOUT] = $this->timeout;
        }

        return $options;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }

        return $out;
    }
}
