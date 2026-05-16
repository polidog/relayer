<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Api;

use JsonException;
use RuntimeException;

/**
 * Turns an API route handler's return value into the HTTP response.
 *
 * The rule is deliberately tiny and predictable — handlers return *data*,
 * the framework encodes it:
 *
 *  - `null`        → `204 No Content`, no body, no `Content-Type`.
 *  - anything else → `Content-Type: application/json; charset=utf-8` and the
 *                     value `json_encode`d (slashes + unicode left unescaped).
 *
 * The status code is whatever the handler left in place. It defaults to
 * `200`, but a handler can override it before returning — this is the
 * escape hatch for error responses with no extra API surface:
 *
 *   \http_response_code(404);
 *   return ['error' => 'not found'];   // → 404 + JSON body
 *
 * Full control over headers / non-JSON bodies is intentionally out of scope
 * for this minimal contract (that was the `Response`-object design that was
 * not chosen). A handler returning a value `json_encode` cannot represent is
 * a server bug and is surfaced loudly as a {@see RuntimeException}.
 */
final class ApiResponder
{
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public static function emit(mixed $result): void
    {
        if (null === $result) {
            // Only downgrade to 204 when the handler didn't choose its own
            // status — a handler that set, say, 202 and returned null still
            // means "no body", but its status intent wins.
            if (200 === \http_response_code()) {
                \http_response_code(204);
            }

            return;
        }

        try {
            $json = \json_encode($result, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'API route handler returned a value that could not be '
                . 'JSON-encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!\headers_sent()) {
            \header('Content-Type: application/json; charset=utf-8');
        }

        echo $json;
    }
}
