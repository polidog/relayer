<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

/**
 * The deterministic file name a native route's binary gets under
 * `var/cache/native/bin/`, derived from its URL pattern.
 *
 * One function, used by the build command (to write the binary), the
 * dispatcher (to find it) and the route listing (to show it) — so the three
 * cannot drift. `/api/users/[id]` becomes `api__users__[id]`: segments
 * joined with `__`, bracketed params kept (they are legal in a file name
 * and keep the listing readable), anything outside `[A-Za-z0-9_.\-\[\]]`
 * folded to `_`. That folding is lossy (`/a/b` and `/a__b` share a slug), so
 * `relayer native:build` refuses routes whose slugs collide.
 */
final class NativeSlug
{
    /** The slug of the root pattern `/`, which has no segments to join. */
    public const ROOT = 'index';

    /**
     * The binary name of `middleware.go`. It is not a route, so it has no
     * pattern to derive a slug from; the build refuses a route whose slug
     * would collide with it (a `src/Pages/middleware/route.go`).
     */
    public const MIDDLEWARE = 'middleware';

    public static function forPattern(string $pattern): string
    {
        $trimmed = \trim($pattern, '/');
        if ('' === $trimmed) {
            return self::ROOT;
        }

        $slug = \str_replace('/', '__', $trimmed);

        return \preg_replace('/[^A-Za-z0-9_.\-\[\]]/', '_', $slug) ?? $slug;
    }

    /**
     * A Go module path for the generated `go.mod`. Module path elements
     * allow letters, digits, `-`, `.`, `_` and `~` only, so the brackets a
     * slug may carry are folded to `_` here.
     */
    public static function moduleName(string $slug): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9_.\-]/', '_', $slug) ?? $slug;

        return 'relayer_native_' . $safe;
    }
}
