<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\RequestPath;

/**
 * The `REQUEST_URI` a native handler gets: the client's own request target,
 * which is what Go's `net/http/cgi` turns into `r.URL` — never the path the
 * request is routed on, which goes in `PATH_INFO`.
 *
 * The routed path is checked before anything native runs, but a guard or a
 * middleware may have rewritten it ({@see Request::withPath()}), so the
 * client's target has to be checked on its own: Go joins it onto the host
 * and `url.Parse`s the result, and a target that fails that (`/%zz`, a
 * control byte anywhere, a malformed escape in the fragment) makes the
 * handler exit before any user code runs.
 *
 * The path of the target is held to the router's rule ({@see RequestPath}),
 * not just Go's: Go would accept `/a%00b` or `/a/%2e%2e/b`, but the
 * framework never lets a route see such a path, and a handler reading
 * `r.URL.Path` must not get one through a rewrite either. The query is left
 * as Go leaves it — raw text, not unescaped by `url.Parse`.
 *
 * An absolute-form target (`http://host/path`, what a client talking to a
 * proxy sends) is reduced to its origin form, because Go prefixes the
 * `Host` header itself and could not parse the two joined.
 */
final class CgiRequestUri
{
    /**
     * The target to hand Go as `REQUEST_URI`, or null when there is none
     * Go and the router would both take — the request is then answered
     * without starting the handler.
     */
    public static function of(Request $request): ?string
    {
        $uri = $request->uri();

        if (1 === \preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            return null;
        }

        $fragmentAt = \strpos($uri, '#');
        $fragment = false === $fragmentAt ? '' : \substr($uri, $fragmentAt);
        if (self::hasMalformedEscape($fragment)) {
            return null;
        }

        $target = false === $fragmentAt ? $uri : \substr($uri, 0, $fragmentAt);
        $queryAt = \strpos($target, '?');
        $path = false === $queryAt ? $target : \substr($target, 0, $queryAt);
        $query = false === $queryAt ? '' : \substr($target, $queryAt);

        if (!\str_starts_with($path, '/')) {
            $path = self::originPath($path);
            if (null === $path) {
                return null;
            }
        }

        if (null === RequestPath::forMatching($path)) {
            return null;
        }

        return $path . $query . $fragment;
    }

    /**
     * The path of an absolute-form target, null for anything else that is
     * not origin form (`*`, garbage).
     */
    private static function originPath(string $target): ?string
    {
        $parts = \parse_url($target);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        return '' === $path ? '/' : $path;
    }

    private static function hasMalformedEscape(string $value): bool
    {
        return 1 === \preg_match('/%(?![0-9A-Fa-f]{2})/', $value);
    }
}
