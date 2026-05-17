<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http\Client;

use JsonException;
use Polidog\Relayer\Db\Database;
use Polidog\Relayer\Http\Response;

/**
 * The result of an {@see HttpClient} call: status, response headers and the
 * raw body, exactly as received.
 *
 * This is a plain carrier, not an output contract — construction is open
 * (the same way {@see Database} hands back plain row
 * arrays rather than a closed type). It is distinct from
 * {@see Response}, which is the *server-side* response
 * an API route returns to the browser; this one is the response an external
 * API returned to us.
 *
 * `status`/`headers`/`body` are always populated even for a 4xx/5xx — a
 * non-2xx is a normal response to inspect, not an exception (see
 * {@see HttpClient}).
 */
final readonly class HttpResponse
{
    /**
     * @param int                   $status  HTTP status code
     * @param array<string, string> $headers response header name => value,
     *                                       in the case the server sent
     * @param string                $body    raw response body ('' when none)
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /**
     * True for a 2xx status. A thin helper so callers don't reimplement the
     * range check at every call site; non-2xx is still a value to handle,
     * never a thrown error.
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the body as JSON (objects as associative arrays).
     *
     * Asking for JSON when the API returned something that is not JSON is a
     * real failure for the caller, so it surfaces loudly as
     * {@see HttpClientException} (mirroring how the rest of the framework's
     * JSON surface fails on bad data) rather than silently returning null.
     *
     * @throws HttpClientException when the body is not valid JSON
     */
    public function json(): mixed
    {
        try {
            return \json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpClientException(
                'Response body is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Case-insensitive single header lookup (HTTP header names are
     * case-insensitive, and servers are inconsistent about casing). Returns
     * null when the header is absent.
     */
    public function header(string $name): ?string
    {
        $needle = \strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (\strtolower($key) === $needle) {
                return $value;
            }
        }

        return null;
    }
}
