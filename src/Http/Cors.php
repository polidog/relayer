<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Closure;

/**
 * A ready-made CORS middleware — the framework's one provided middleware,
 * not a parallel subsystem. Wire it in `src/Pages/middleware.php`:
 *
 *   use Polidog\Relayer\Http\Cors;
 *
 *   return Cors::middleware(['origins' => ['https://app.example.com']]);
 *
 * To combine it with your own logic, compose the closures by hand (the
 * middleware contract is a single `fn(Request, $next)` — there is no chain
 * runner, by design):
 *
 *   $cors = Cors::middleware([...]);
 *   return fn ($req, $next) => $cors($req, fn ($r) => $next($r));
 *
 * Defaults are conservative: no origins allowed until you list them (or
 * pass `['*']`), no credentials. A preflight (`OPTIONS` carrying
 * `Access-Control-Request-Method`) is answered with `204` and the request
 * does NOT continue to the route; an actual request gets the
 * `Access-Control-Allow-Origin` header and then proceeds.
 *
 * A preflight from a disallowed origin is still answered `204`, just
 * without an `Access-Control-Allow-Origin` header — authorization is
 * conveyed by the presence/absence of that header (how the browser
 * enforces CORS), not by the status code. This middleware does not turn an
 * unauthorized preflight into a `403`; if you need that distinction in
 * ops/logs, do it in your own composed middleware.
 */
final class Cors
{
    /**
     * @param array{
     *     origins?: list<string>,
     *     methods?: list<string>,
     *     headers?: list<string>,
     *     credentials?: bool,
     *     maxAge?: int,
     * } $config
     *
     * @return Closure(Request, Closure): void
     */
    public static function middleware(array $config = []): Closure
    {
        $origins = $config['origins'] ?? [];
        $methods = $config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $headers = $config['headers'] ?? ['Content-Type'];
        $credentials = $config['credentials'] ?? false;
        $maxAge = $config['maxAge'] ?? 600;

        return static function (Request $request, Closure $next) use (
            $origins,
            $methods,
            $headers,
            $credentials,
            $maxAge,
        ): void {
            $origin = $request->header('origin');

            // Not a CORS request (no Origin) — nothing to add, just continue.
            if (null === $origin) {
                $next($request);

                return;
            }

            $allowOrigin = self::resolveAllowedOrigin($origin, $origins, $credentials);

            if (!\headers_sent()) {
                // The response content depends on the Origin request header
                // (an allowed origin gets ACAO, a disallowed one doesn't),
                // so it varies by Origin for any shared cache — advertise
                // that even when no ACAO is produced, or a URL-keyed cache
                // could serve an allowed response to a disallowed origin.
                // A literal `*` is the one origin-independent case.
                if ('*' !== $allowOrigin) {
                    \header('Vary: Origin', false);
                }
                if (null !== $allowOrigin) {
                    \header('Access-Control-Allow-Origin: ' . $allowOrigin);
                    if ($credentials) {
                        \header('Access-Control-Allow-Credentials: true');
                    }
                }
            }

            // Preflight: the browser's OPTIONS probe before the real request.
            $isPreflight = 'OPTIONS' === $request->method
                && null !== $request->header('access-control-request-method');

            if ($isPreflight) {
                if (!\headers_sent()) {
                    \header('Access-Control-Allow-Methods: ' . \implode(', ', $methods));
                    \header('Access-Control-Allow-Headers: ' . \implode(', ', $headers));
                    \header('Access-Control-Max-Age: ' . $maxAge);
                }
                \http_response_code(204);

                // Preflight is answered here; the route never runs.
                return;
            }

            $next($request);
        };
    }

    /**
     * Decide the `Access-Control-Allow-Origin` value, or null when the
     * request Origin is not allowed (the header is then omitted and the
     * browser blocks the cross-origin read — same-origin still works).
     *
     * `*` cannot be combined with credentials per the CORS spec, so when
     * credentials are on we reflect the concrete Origin instead of `*`.
     *
     * @param list<string> $origins
     */
    private static function resolveAllowedOrigin(string $origin, array $origins, bool $credentials): ?string
    {
        if (\in_array('*', $origins, true)) {
            return $credentials ? $origin : '*';
        }

        return \in_array($origin, $origins, true) ? $origin : null;
    }
}
