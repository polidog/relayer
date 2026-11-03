<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Keeps a bodiless response (`204 No Content`, `304 Not Modified`) from
 * carrying a `Content-Type`.
 *
 * PHP sends its `default_mimetype` (`text/html; charset=UTF-8`) with every
 * response unless a `Content-Type` was set, and `header_remove()` alone
 * does not turn that default off: it only drops a queued header. Setting an
 * empty `Content-Type` first is what switches the default off, and removing
 * it again keeps the empty header itself off the wire.
 *
 * @internal used by {@see Response}, {@see CachePolicy} and {@see Cors}
 */
final class BodilessHeaders
{
    private function __construct() {}

    /**
     * Whether $status is one that never carries a body.
     */
    public static function isBodiless(int $status): bool
    {
        return 204 === $status || 304 === $status;
    }

    /**
     * Drop any queued `Content-Type` and PHP's default one. No-op once
     * headers have been sent.
     */
    public static function removeContentType(): void
    {
        if (\headers_sent()) {
            return;
        }

        \header('Content-Type:');
        \header_remove('Content-Type');
    }
}
