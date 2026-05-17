<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use RuntimeException;

/**
 * Thrown by `PageContext::abort()` / `PageContext::notFound()` (typically from
 * inside a page factory or a form-action handler). The framework catches this
 * in AppRouter and renders the error page with the given status instead of the
 * page — handler code just calls `$ctx->notFound()` / `$ctx->abort(403)` and
 * lets the exception unwind, exactly like `redirect()` and `requireAuth()` do.
 *
 * This is the control-flow primitive that keeps `http_response_code()` a
 * framework internal: page authors never set a status code by hand, they
 * declare intent ("this is gone", "this is forbidden") and the router maps it
 * to a status + the project's `error.psx` (or the built-in fallback page).
 */
final class HttpException extends RuntimeException
{
    public readonly string $reason;

    public function __construct(public readonly int $status, ?string $reason = null)
    {
        $this->reason = $reason ?? self::reasonPhrase($status);

        parent::__construct("HTTP {$this->status} {$this->reason}");
    }

    /**
     * Standard reason phrase for the error statuses an `abort()` realistically
     * carries. Unknown codes degrade to a generic class label rather than
     * failing — the status is what matters to the client, the phrase is only
     * the human-facing message on the error page.
     */
    public static function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            413 => 'Payload Too Large',
            415 => 'Unsupported Media Type',
            418 => "I'm a teapot",
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            429 => 'Too Many Requests',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => $status >= 500 ? 'Server Error' : 'Client Error',
        };
    }
}
