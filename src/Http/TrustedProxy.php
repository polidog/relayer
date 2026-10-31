<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Closure;
use InvalidArgumentException;

/**
 * Trusted-proxy middleware: resolve the real client IP from a forwarding
 * header, but only when the immediate hop (`REMOTE_ADDR`) is a proxy you
 * trust. The result is exposed as {@see Request::clientIp()};
 * {@see Request::ip()} keeps reporting `REMOTE_ADDR` unchanged.
 * Wire it in `src/Pages/middleware.php`:
 *
 *   use Polidog\Relayer\Http\TrustedProxy;
 *
 *   return TrustedProxy::middleware([
 *       'preset'  => 'cloudflare',
 *       'proxies' => ['173.245.48.0/20', '2400:cb00::/32', ...], // Cloudflare's published ranges
 *   ]);
 *
 * Presets:
 *
 * - `cloudflare` — `CF-Connecting-IP`. Honored only when `REMOTE_ADDR` is
 *   inside `proxies`; Cloudflare's ranges are NOT bundled (they change),
 *   fetch them from https://www.cloudflare.com/ips-v4 and /ips-v6 and pass
 *   them in. Note this "immediate hop" check cannot hold when the app runs
 *   on Fly.io: there `REMOTE_ADDR` is the Fly proxy, so use the `fly` preset
 *   together with {@see OriginGuard} instead.
 * - `fly` — `Fly-Client-IP`. The Fly proxy is the only ingress to a Fly
 *   app, so `proxies` may be omitted; when given it is enforced.
 * - `xff` — `X-Forwarded-For`, walked from the right: every trusted proxy
 *   is skipped and the first address that is not one of yours is the
 *   client. `proxies` is required.
 *
 * Instead of a preset, `header` names any single-IP header (`True-Client-IP`,
 * `X-Real-IP`, …); `proxies` is then required. Addresses are matched as
 * plain IPs or CIDR ranges, IPv4 and IPv6. A header value that is not a
 * valid IP is ignored — `clientIp()` then falls back to `ip()`.
 */
final class TrustedProxy
{
    public const string PRESET_CLOUDFLARE = 'cloudflare';
    public const string PRESET_FLY = 'fly';
    public const string PRESET_XFF = 'xff';

    private const string HEADER_CLOUDFLARE = 'CF-Connecting-IP';
    private const string HEADER_FLY = 'Fly-Client-IP';
    private const string HEADER_XFF = 'X-Forwarded-For';

    /**
     * @param array{
     *     preset?: string,
     *     header?: string,
     *     proxies?: list<string>,
     * } $config
     *
     * @return Closure(Request, Closure): void
     */
    public static function middleware(array $config = []): Closure
    {
        $preset = $config['preset'] ?? null;
        $proxies = $config['proxies'] ?? [];

        foreach ($proxies as $range) {
            if (!self::isValidRange($range)) {
                throw new InvalidArgumentException(\sprintf('TrustedProxy: "%s" is not a valid IP address or CIDR range.', $range));
            }
        }

        $header = match ($preset) {
            null => $config['header'] ?? null,
            self::PRESET_CLOUDFLARE => self::HEADER_CLOUDFLARE,
            self::PRESET_FLY => self::HEADER_FLY,
            self::PRESET_XFF => self::HEADER_XFF,
            default => throw new InvalidArgumentException(\sprintf(
                'TrustedProxy: unknown preset "%s" (expected "%s", "%s" or "%s").',
                $preset,
                self::PRESET_CLOUDFLARE,
                self::PRESET_FLY,
                self::PRESET_XFF,
            )),
        };
        if (null === $header || '' === \trim($header)) {
            throw new InvalidArgumentException('TrustedProxy: pass a "preset" or a non-empty "header".');
        }
        if ([] === $proxies && self::PRESET_FLY !== $preset) {
            throw new InvalidArgumentException(\sprintf(
                'TrustedProxy: "proxies" is required for %s — without a trust list the %s header is client-supplied.',
                null === $preset ? 'a custom header' : \sprintf('the "%s" preset', $preset),
                $header,
            ));
        }
        $walkXff = self::PRESET_XFF === $preset;

        return static function (Request $request, Closure $next) use ($header, $proxies, $walkXff): void {
            $resolved = self::resolve($request, $header, $proxies, $walkXff);

            $next(null === $resolved ? $request : $request->withClientIp($resolved));
        };
    }

    /**
     * Whether `$ip` is one of `$ranges` (plain addresses or CIDR, v4 / v6).
     * A malformed `$ip` never matches.
     *
     * @param list<string> $ranges
     */
    public static function ipMatches(string $ip, array $ranges): bool
    {
        $packed = self::pack($ip);
        if (null === $packed) {
            return false;
        }

        foreach ($ranges as $range) {
            if (self::inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $proxies
     */
    private static function resolve(Request $request, string $header, array $proxies, bool $walkXff): ?string
    {
        $remote = $request->ip();
        if (null === $remote) {
            return null;
        }
        // An empty trust list is only reachable via the `fly` preset.
        if ([] !== $proxies && !self::ipMatches($remote, $proxies)) {
            return null;
        }

        $value = $request->header($header);
        if (null === $value) {
            return null;
        }

        if (!$walkXff) {
            return self::validIp(\trim($value));
        }

        // X-Forwarded-For: "client, proxy1, proxy2" — each hop appends the
        // address it received from. Walk from the right, past the proxies we
        // trust; the first address that is not ours was put there by a
        // trusted proxy and is the client. Anything further left is
        // client-controlled and ignored.
        $hops = \array_reverse(\array_map(\trim(...), \explode(',', $value)));
        foreach ($hops as $hop) {
            $candidate = self::validIp($hop);
            if (null === $candidate) {
                return null;
            }
            if (!self::ipMatches($candidate, $proxies)) {
                return $candidate;
            }
        }

        // Every listed hop is a trusted proxy — no client address to report.
        return null;
    }

    private static function validIp(string $value): ?string
    {
        return false === \filter_var($value, \FILTER_VALIDATE_IP) ? null : $value;
    }

    private static function isValidRange(string $range): bool
    {
        [$address, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
        $packed = self::pack((string) $address);
        if (null === $packed) {
            return false;
        }
        if (null === $bits) {
            return true;
        }

        return 1 === \preg_match('/^\d{1,3}$/', $bits) && (int) $bits <= \strlen($packed) * 8;
    }

    private static function pack(string $ip): ?string
    {
        if (false === \filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = \inet_pton($ip);

        return false === $packed ? null : $packed;
    }

    private static function inRange(string $packedIp, string $range): bool
    {
        [$address, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
        $packedRange = self::pack((string) $address);
        if (null === $packedRange || \strlen($packedRange) !== \strlen($packedIp)) {
            return false; // v4 vs v6 never match
        }

        $prefix = null === $bits ? \strlen($packedRange) * 8 : (int) $bits;
        $fullBytes = \intdiv($prefix, 8);
        if (\substr($packedIp, 0, $fullBytes) !== \substr($packedRange, 0, $fullBytes)) {
            return false;
        }

        $remaining = $prefix % 8;
        if (0 === $remaining) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (\ord($packedIp[$fullBytes]) & $mask) === (\ord($packedRange[$fullBytes]) & $mask);
    }
}
