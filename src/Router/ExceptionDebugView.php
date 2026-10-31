<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Throwable;

/**
 * Dev-only renderings of an uncaught exception: an HTML page for page routes
 * and a JSON body for endpoints, both with the message, location and stack
 * trace of the exception and everything it wraps.
 *
 * {@see AppRouter} only reaches this class when {@see AppRouter::setDebug()}
 * is on (`APP_ENV=dev`), because everything here is exactly what production
 * must not send to a client.
 *
 * @internal
 */
final class ExceptionDebugView
{
    public static function html(Throwable $exception): string
    {
        $title = self::h($exception::class . ': ' . $exception->getMessage());
        $details = self::fragment($exception);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>500 - {$title}</title>
                <style>
                    body { font-family: system-ui, sans-serif; margin: 2rem; }
                    pre { background: #f4f4f4; color: #222; overflow: auto; padding: 1rem; }
                </style>
            </head>
            <body>
                <h1>500 - Uncaught exception</h1>
                {$details}
            </body>
            </html>
            HTML;
    }

    /**
     * The trace alone, for appending to a response whose headers and first
     * bytes are already on the wire.
     */
    public static function fragment(Throwable $exception): string
    {
        return '<pre>' . self::h((string) $exception) . '</pre>';
    }

    /**
     * Encoded here rather than through `Response::json()` so a message with
     * invalid UTF-8 (a binary value in a driver error, say) cannot fail the
     * very response that is reporting it.
     */
    public static function json(Throwable $exception, string $error): string
    {
        $encoded = \json_encode(
            ['error' => $error, 'exception' => self::describe($exception)],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return false === $encoded ? '{"error":"Internal Server Error"}' : $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Throwable $exception): array
    {
        $description = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => \explode("\n", $exception->getTraceAsString()),
        ];

        $previous = $exception->getPrevious();
        if (null !== $previous) {
            $description['previous'] = self::describe($previous);
        }

        return $description;
    }

    private static function h(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
