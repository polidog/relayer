<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * The one place that knows how a percent-encoded URL path maps to what the
 * router compares against.
 *
 * Decoding is per segment, after splitting on `/`, and exactly once: `%2F`
 * never introduces a segment boundary and `%252e` is the literal text `%2e`.
 *
 * A path is *unroutable* when a segment is not something a route may safely
 * receive — a malformed escape, a control character (`%00`, and `%0A`, which
 * `$` in a route regex would otherwise accept at the end of a path), invalid
 * UTF-8 (overlong `%C0%AE` included) or a dot-segment in any spelling
 * (`..`, `%2e%2e`, `..%2Fetc`, `..%5Cetc`). Such a path matches no route.
 */
final class RequestPath
{
    /**
     * Stands in for a decoded `/` inside a segment while matching. Route
     * regexes split on a real `/`, and a routable path can never contain
     * this byte, so it cannot collide with anything in the path.
     */
    private const SEGMENT_SLASH = "\0";

    /**
     * Characters a canonical segment keeps literal; everything else is
     * escaped. `:` is left out on purpose: `parse_url()` — which the router
     * runs on this form — returns false for a path like `/a:80`.
     */
    private const CANONICAL_ESCAPE = '/[^A-Za-z0-9\-._~!$&\'()*+,;=@]/';

    /**
     * The single spelling of `$path`: two paths the router treats alike have
     * the same canonical form (`/%61dmin`, `/admin/` and `/admin`, `/%e6%97%a5`
     * and raw `/日`). This is what makes a string comparison on {@see Request::$path}
     * see the path the router will route. An unroutable path is returned
     * unchanged — it reaches no route, whatever a comparison makes of it.
     */
    public static function canonicalize(string $path): string
    {
        $segments = self::decodeSegments($path);
        if (null === $segments) {
            return $path;
        }

        $encoded = [];
        foreach ($segments as $segment) {
            $encoded[] = (string) \preg_replace_callback(
                self::CANONICAL_ESCAPE,
                static fn (array $m): string => '%' . \strtoupper(\bin2hex($m[0])),
                $segment,
            );
        }

        $canonical = \rtrim(\implode('/', $encoded), '/');

        return '' === $canonical ? '/' : $canonical;
    }

    /**
     * `$path` decoded for comparing against, e.g. `/日本語`. An encoded `/`
     * decodes too, so do not split the result into segments. An unroutable
     * path is returned unchanged rather than exposing its decoded bytes.
     */
    public static function decode(string $path): string
    {
        $segments = self::decodeSegments($path);

        return null === $segments ? $path : \implode('/', $segments);
    }

    /**
     * The form a route regex runs against: decoded segments joined by `/`,
     * with a decoded `/` hidden from the regex. Null for an unroutable path.
     */
    public static function forMatching(string $path): ?string
    {
        $segments = self::decodeSegments($path);
        if (null === $segments) {
            return null;
        }

        return \implode('/', \str_replace('/', self::SEGMENT_SLASH, $segments));
    }

    /**
     * Undo {@see forMatching()} for a value captured out of its result.
     */
    public static function restoreCaptured(string $captured): string
    {
        return \str_replace(self::SEGMENT_SLASH, '/', $captured);
    }

    /**
     * @return null|list<string> null when any segment is unroutable
     */
    private static function decodeSegments(string $path): ?array
    {
        $decoded = [];
        foreach (\explode('/', $path) as $segment) {
            $value = self::decodeSegment($segment);
            if (null === $value) {
                return null;
            }
            $decoded[] = $value;
        }

        return $decoded;
    }

    private static function decodeSegment(string $segment): ?string
    {
        if (1 === \preg_match('/%(?![0-9A-Fa-f]{2})/', $segment)) {
            return null;
        }

        $decoded = \rawurldecode($segment);

        if (1 === \preg_match('/[\x00-\x1F\x7F]/', $decoded) || 1 !== \preg_match('//u', $decoded)) {
            return null;
        }

        // A segment is one path component to the router, but `..%2Fetc` is
        // two to whoever joins the param onto a filesystem path.
        foreach (\preg_split('#[/\\\]#', $decoded) ?: [] as $component) {
            if ('.' === $component || '..' === $component) {
                return null;
            }
        }

        return $decoded;
    }
}
