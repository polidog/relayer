<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use JsonException;
use RuntimeException;

/**
 * The response an API route (`route.php`) handler returns.
 *
 * This is the single, explicit output contract for API routes — handlers
 * build a `Response` and return it; the framework sends it verbatim. There
 * is deliberately no "return raw data and the framework guesses" path: one
 * way to produce a response, status and headers always explicit. (`page`
 * rendering is a separate pipeline and does not use this type.)
 *
 *   // src/Pages/api/users/[id]/route.php
 *   return [
 *       'GET' => function (PageContext $ctx, UserRepository $users): Response {
 *           $user = $users->find($ctx->params['id']);
 *           return null !== $user
 *               ? Response::json($user)
 *               : Response::json(['error' => 'Not Found'], 404);
 *       },
 *   ];
 *
 * Construction is closed; use the named factories. `json()` encodes eagerly
 * (slashes + unicode left unescaped, matching the framework's other JSON
 * surface), so a value that cannot be encoded fails loudly at the point the
 * handler builds the response — a server bug, surfaced as a
 * {@see RuntimeException}, not a half-sent body.
 */
final readonly class Response
{
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, string> $headers header name => value, kept in
     *                                       the case the caller gave
     */
    private function __construct(
        public int $status,
        public array $headers,
        public ?string $body,
    ) {}

    /**
     * JSON body with `Content-Type: application/json; charset=utf-8` (a
     * caller-supplied `Content-Type` in $headers wins). Encoding is eager.
     *
     * @param array<string, string> $headers
     *
     * @throws RuntimeException when $data cannot be JSON-encoded (a server
     *                          bug — caught nowhere, surfaced loudly)
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        try {
            $body = \json_encode($data, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'API route handler returned a value that could not be '
                . 'JSON-encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return new self(
            $status,
            self::mergeHeaders(['Content-Type' => 'application/json; charset=utf-8'], $headers),
            $body,
        );
    }

    /**
     * Plain-text body with `Content-Type: text/plain; charset=utf-8` (a
     * caller-supplied `Content-Type` in $headers wins).
     *
     * @param array<string, string> $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            self::mergeHeaders(['Content-Type' => 'text/plain; charset=utf-8'], $headers),
            $body,
        );
    }

    /**
     * No body, no `Content-Type` — `204 No Content` by default. The status
     * is explicit here; there is no implicit "null becomes 204" magic.
     */
    public static function noContent(int $status = 204): self
    {
        return new self($status, [], null);
    }

    /**
     * A `Location` redirect with no body — `302 Found` by default.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location], null);
    }

    /**
     * Escape hatch: a raw body with no implicit `Content-Type`. Use for
     * anything the typed factories don't cover (CSV, an empty 201, …)
     * without reintroducing a raw-data return contract.
     *
     * @param array<string, string> $headers
     */
    public static function make(?string $body = null, int $status = 200, array $headers = []): self
    {
        return new self($status, self::mergeHeaders([], $headers), $body);
    }

    /**
     * A copy with one header set/overridden (case-insensitive). Used by the
     * router to attach `Allow` to synthesized `OPTIONS` / `405` responses.
     */
    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->status,
            self::mergeHeaders($this->headers, [$name => $value]),
            $this->body,
        );
    }

    /**
     * A copy with the body stripped, status and headers kept — what an
     * auto-synthesized `HEAD` sends after running the `GET` handler.
     */
    public function withoutBody(): self
    {
        return new self($this->status, $this->headers, null);
    }

    /**
     * Set the status, emit the headers (skipped if output already started,
     * mirroring the rest of the router), then echo the body if any. Status
     * is always set: with this contract the handler chose it explicitly.
     */
    public function send(): void
    {
        \http_response_code($this->status);

        if (!\headers_sent()) {
            foreach ($this->headers as $name => $value) {
                \header($name . ': ' . $value);
            }
        }

        if (null !== $this->body) {
            echo $this->body;
        }
    }

    /**
     * Merge $overrides onto $base, replacing case-insensitively but keeping
     * each header in the case it was last given (so `Content-Type` stays
     * canonical unless the caller deliberately respelled it).
     *
     * @param array<string, string> $base
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function mergeHeaders(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $name => $value) {
            $lower = \strtolower($name);
            foreach (\array_keys($merged) as $existing) {
                if (\strtolower($existing) === $lower) {
                    unset($merged[$existing]);
                }
            }
            $merged[$name] = $value;
        }

        return $merged;
    }
}
