<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Origin-protection middleware: reject requests that did not come through
 * your CDN / reverse proxy.
 *
 * The CDN (Cloudflare Transform Rules, a Fly.io/nginx edge, …) stamps a
 * secret header on every request it forwards; this middleware checks that
 * header and answers `403` when it is missing or wrong, so a request that
 * bypasses the CDN (`curl https://<app>.fly.dev`) never reaches a route.
 * Wire it in `src/Pages/middleware.php`:
 *
 *   use Polidog\Relayer\Http\OriginGuard;
 *
 *   return OriginGuard::middleware([
 *       'header'         => 'X-Origin-Secret',
 *       'secretsFromEnv' => ['ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS'],
 *       'except'         => ['/healthz'],
 *       'mode'           => 'enforce', // or 'report'
 *   ]);
 *
 * - **Off by default**: with no (non-empty) secret configured every request
 *   passes through, so local runs and CI never turn into surprise 403s.
 * - `secretsFromEnv` names environment variables to read the secrets from,
 *   resolved the way the framework reads its own config (`$_ENV`, then
 *   `$_SERVER`, then `getenv()` — so a `.env.local` and a real `fly secrets`
 *   variable both work under any `variables_order`). Unset / blank names
 *   are skipped. `secrets` takes literal values instead (or in addition).
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
            ...\array_map(self::readEnv(...), $config['secretsFromEnv'] ?? []),
            ...\array_values($config['secrets'] ?? []),
        ]);
        $except = $config['except'] ?? [];
        $mode = $config['mode'] ?? self::MODE_ENFORCE;
        $logger = $config['logger'] ?? null;

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

            // The presented value is deliberately not logged: a near-miss
            // would leak most of the secret into the log sink.
            $context = [
                'reason' => $reason,
                'header' => $header,
                'method' => $request->method,
                'path' => $request->path,
                'ip' => $request->ip(),
            ];

            if (self::MODE_REPORT === $mode) {
                ($logger ?? self::fallbackLogger())->warning(
                    'OriginGuard would reject {method} {path} from {ip}: origin secret {reason} (report mode)',
                    $context,
                );
                $next($request);

                return;
            }

            ($logger ?? self::fallbackLogger())->warning(
                'OriginGuard rejected {method} {path} from {ip}: origin secret {reason}',
                $context,
            );

            if (!\headers_sent()) {
                \header('Content-Type: text/plain; charset=utf-8');
            }
            \http_response_code(403);
            echo 'Forbidden';
            // The route never runs.
        };
    }

    /**
     * Same lookup order as the framework's own env reading: Symfony Dotenv
     * populates `$_ENV` / `$_SERVER` (not `getenv()`), while a real process
     * variable may be visible only through `getenv()` when `variables_order`
     * lacks `E` (php.ini-production) — as under the built-in server.
     */
    private static function readEnv(string $name): ?string
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);

        return \is_string($raw) ? $raw : null;
    }

    /**
     * Keep only non-empty strings. Unset env slots arrive as null, and a
     * hand-built `secrets` list may carry `false` / '' from a failed lookup
     * — none of those must count as a secret (an empty one would match an
     * empty header).
     *
     * @param array<mixed> $secrets
     *
     * @return list<string>
     */
    private static function normalizeSecrets(array $secrets): array
    {
        $normalized = [];
        foreach ($secrets as $secret) {
            if (\is_string($secret) && '' !== $secret) {
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
     * Used when no `logger` is handed in: `middleware.php` is a plain
     * required file, so reaching the DI logger takes an explicit
     * `Relayer::container()->get(LoggerInterface::class)`. Without it the
     * report still lands somewhere visible (the SAPI error log).
     */
    private static function fallbackLogger(): LoggerInterface
    {
        return new Logger('origin-guard', [new ErrorLogHandler()]);
    }
}
