<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Polidog\Relayer\Env;
use Polidog\Relayer\Relayer;
use Psr\Log\LoggerInterface;

/**
 * Origin-protection middleware: reject requests that did not come through
 * your CDN / reverse proxy.
 *
 * The CDN (Cloudflare Transform Rules, a Fly.io/nginx edge, …) stamps a
 * secret header on every request it forwards; this middleware checks that
 * header and answers `403` when it is missing or wrong, so a request that
 * bypasses the CDN (`curl https://<app>.fly.dev`) never reaches a route.
 *
 * The framework wires it for you from the environment (see
 * {@see GuardFactory}): set `ORIGIN_SECRET` and {@see Relayer::boot()}
 * installs it as the router **guard**, which runs before everything —
 * deferred component fetches (`/_defer/{name}`), the dev profiler and the
 * root `middleware.php`. Do not put it in `middleware.php`: that wraps
 * route dispatch only and is bypassed by those framework endpoints.
 *
 *   ORIGIN_SECRET=<long random>          # on
 *   ORIGIN_SECRET_PREVIOUS=<old>         # also accepted while rotating
 *   ORIGIN_GUARD_HEADER=X-Origin-Secret  # default
 *   ORIGIN_GUARD_EXCEPT=/healthz
 *   ORIGIN_GUARD_MODE=enforce            # or report
 *
 * To compose your own, build it by hand and hand it to the router:
 *
 *   Relayer::boot(__DIR__ . '/..')
 *       ->setGuard(OriginGuard::middleware([
 *           'secretsFromEnv' => ['ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS'],
 *           'except'         => ['/healthz'],
 *           'logger'         => Relayer::container()->get(LoggerInterface::class),
 *       ]))
 *       ->run();
 *
 * - **Off by default**: with no (non-blank) secret configured every request
 *   passes through, so local runs and CI never turn into surprise 403s.
 * - `secretsFromEnv` names environment variables to read the secrets from,
 *   via {@see Env::read()} (`$_ENV`, then `$_SERVER`, then `getenv()`;
 *   trimmed, blank = unset — so a `.env.local` and a real `fly secrets`
 *   variable both work under any `variables_order`, and a trailing newline
 *   from `fly secrets import` does not break every request). `secrets`
 *   takes literal values instead (or in addition); they are trimmed too.
 * - Either list may hold several values so a rotation can accept the old
 *   and the new secret at once; each candidate is compared with
 *   `hash_equals` (constant time).
 * - `except` paths skip the check (platform health checks arrive without
 *   the header). An entry is an exact path, or a prefix when it ends in `*`
 *   (`/_health/*`).
 * - `mode: report` logs what *would* be rejected and lets the request
 *   continue — use it to dry-run before enforcing.
 * - Log lines carry the method, path and `REMOTE_ADDR` only — never a
 *   configured secret nor the value the client presented.
 *
 * Pass the secrets in from the environment (`fly secrets set`, a gitignored
 * `.env.local`); do not write them into a committed config file.
 */
final class OriginGuard
{
    public const string MODE_ENFORCE = 'enforce';
    public const string MODE_REPORT = 'report';

    private const string DEFAULT_HEADER = 'X-Origin-Secret';

    /**
     * @param array{
     *     header?: string,
     *     secrets?: array<mixed>,
     *     secretsFromEnv?: list<string>,
     *     except?: list<string>,
     *     mode?: string,
     *     logger?: LoggerInterface,
     * } $config
     *
     * @return Closure(Request, Closure): void
     */
    public static function middleware(array $config = []): Closure
    {
        $header = $config['header'] ?? self::DEFAULT_HEADER;
        $secrets = self::normalizeSecrets([
            ...\array_map(Env::read(...), $config['secretsFromEnv'] ?? []),
            ...\array_values($config['secrets'] ?? []),
        ]);
        $except = $config['except'] ?? [];
        $mode = $config['mode'] ?? self::MODE_ENFORCE;
        // Resolved once here, not per rejected request: a bypass scan must
        // not allocate a logger per hit.
        $logger = $config['logger'] ?? self::fallbackLogger();

        if ('' === \trim($header)) {
            throw new InvalidArgumentException('OriginGuard: "header" must be a non-empty header name.');
        }
        if (self::MODE_ENFORCE !== $mode && self::MODE_REPORT !== $mode) {
            throw new InvalidArgumentException(\sprintf(
                'OriginGuard: "mode" must be "%s" or "%s", "%s" given.',
                self::MODE_ENFORCE,
                self::MODE_REPORT,
                $mode,
            ));
        }

        return static function (Request $request, Closure $next) use (
            $header,
            $secrets,
            $except,
            $mode,
            $logger,
        ): void {
            // No secret configured: the guard is disabled, not failing.
            if ([] === $secrets || self::isExcepted($request->path, $except)) {
                $next($request);

                return;
            }

            $presented = $request->header($header);
            $reason = null === $presented ? 'missing' : (self::matches($presented, $secrets) ? null : 'mismatch');

            if (null === $reason) {
                $next($request);

                return;
            }

            $enforce = self::MODE_ENFORCE === $mode;

            // One message template for both modes (one pattern to search
            // for); `outcome` tells them apart. The presented value is
            // deliberately not logged: a near-miss would leak most of the
            // secret into the log sink.
            $logger->warning('OriginGuard {outcome} {method} {path} from {ip}: origin secret {reason}', [
                'outcome' => $enforce ? 'rejected' : 'reported',
                'mode' => $mode,
                'reason' => $reason,
                'header' => $header,
                'method' => $request->method,
                'path' => $request->path,
                'ip' => $request->ip(),
            ]);

            if (!$enforce) {
                $next($request);

                return;
            }

            if (!\headers_sent()) {
                \header('Content-Type: text/plain; charset=utf-8');
            }
            \http_response_code(403);
            echo 'Forbidden';
            // The route never runs.
        };
    }

    /**
     * Keep only strings that are non-blank after trimming. Unset env slots
     * arrive as null, a hand-built `secrets` list may carry `false` / '' from
     * a failed lookup, and a value pasted with a trailing newline or made of
     * whitespace must neither count as a secret (an empty one would match an
     * empty header) nor silently fail to match the value the CDN sends.
     *
     * @param array<mixed> $secrets
     *
     * @return list<string>
     */
    private static function normalizeSecrets(array $secrets): array
    {
        $normalized = [];
        foreach ($secrets as $secret) {
            if (!\is_string($secret)) {
                continue;
            }
            $secret = \trim($secret);
            if ('' !== $secret) {
                $normalized[] = $secret;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $secrets
     */
    private static function matches(string $presented, array $secrets): bool
    {
        $ok = false;
        foreach ($secrets as $secret) {
            // Compare every candidate (no early return) so the timing does
            // not reveal which rotation slot matched.
            $ok = \hash_equals($secret, $presented) || $ok;
        }

        return $ok;
    }

    /**
     * @param list<string> $except
     */
    private static function isExcepted(string $path, array $except): bool
    {
        foreach ($except as $pattern) {
            if (\str_ends_with($pattern, '*')) {
                if (\str_starts_with($path, \substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }
            if ($pattern === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Used when no `logger` is handed in, so the report still lands
     * somewhere visible (the SAPI error log). Pass the DI logger
     * (`Relayer::container()->get(LoggerInterface::class)`) to get the
     * app's configured sink instead.
     */
    private static function fallbackLogger(): LoggerInterface
    {
        return new Logger('origin-guard', [new ErrorLogHandler()]);
    }
}
