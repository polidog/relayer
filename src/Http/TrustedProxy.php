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
 *
 *   use Polidog\Relayer\Http\TrustedProxy;
 *
 *   TrustedProxy::middleware([
 *       'preset'  => 'cloudflare',
 *       'proxies' => ['173.245.48.0/20', '2400:cb00::/32', ...], // Cloudflare's published ranges
 *   ]);
 *
 * A **layer** is `preset` (or `header`) plus `proxies`:
 *
 * - `cloudflare` — `CF-Connecting-IP`. Cloudflare's ranges are NOT bundled
 *   (they change): fetch https://www.cloudflare.com/ips-v4 and /ips-v6 and
 *   pass them in.
 * - `fly` — `Fly-Client-IP`. The Fly proxy reaches your app over Fly's
 *   private network (6PN, `fdaa::/16`); pass that as `proxies` (check your
 *   app's logs for the exact source if your setup differs).
 * - `xff` — `X-Forwarded-For`, walked from the right: every trusted proxy
 *   is skipped and the first address that is not one of yours is the
 *   client.
 * - `header` names any other single-IP header (`True-Client-IP`,
 *   `X-Real-IP`, …). `preset` and `header` are mutually exclusive.
 *
 * `proxies` (plain IPs or CIDR ranges, IPv4 / IPv6; an IPv4-mapped IPv6
 * address such as `::ffff:1.2.3.4` matches IPv4 ranges) is **required** —
 * a forwarding header from an unverified hop is client-supplied. The only
 * way to skip the check is the explicit `'trustAnyHop' => true`, for the
 * case where the proxy is provably the sole ingress to the process; never
 * set it on a server that can be reached directly.
 *
 * **Several proxies in a row** (`chain`): behind Cloudflare → Fly, the hop
 * is Fly, `Fly-Client-IP` is *Cloudflare's* address, and the user is in
 * `CF-Connecting-IP`. List the layers outermost-last; each one is applied
 * only if the address resolved so far is one of that layer's proxies, and
 * the last address that could be verified is the client:
 *
 *   TrustedProxy::middleware(['chain' => [
 *       ['preset' => 'fly',        'proxies' => ['fdaa::/16']],
 *       ['preset' => 'cloudflare', 'proxies' => $cloudflareRanges],
 *   ]]);
 *
 * So a request straight to `xxx.fly.dev` resolves to the caller's address
 * (the Fly layer applies, the Cloudflare layer does not because that
 * address is not Cloudflare's), while a forged `CF-Connecting-IP` from a
 * non-Cloudflare source is ignored. Combine with {@see OriginGuard} to
 * refuse such direct requests altogether. A header value that is not a
 * valid IP stops the resolution — `clientIp()` then falls back to `ip()`.
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
     *     trustAnyHop?: bool,
     *     chain?: list<array{preset?: string, header?: string, proxies?: list<string>, trustAnyHop?: bool}>,
     * } $config
     *
     * @return Closure(Request, Closure): void
     */
    public static function middleware(array $config = []): Closure
    {
        if (isset($config['chain'])) {
            if (\count($config) > 1) {
                throw new InvalidArgumentException('TrustedProxy: "chain" cannot be combined with a top-level layer.');
            }
            if ([] === $config['chain']) {
                throw new InvalidArgumentException('TrustedProxy: "chain" must contain at least one layer.');
            }
            $layers = \array_map(self::layer(...), $config['chain']);
        } else {
            $layers = [self::layer($config)];
        }

        return static function (Request $request, Closure $next) use ($layers): void {
            $resolved = self::resolve($request, $layers);

            $next(null === $resolved ? $request : $request->withClientIp($resolved));
        };
    }

    /**
     * Whether `$ip` is one of `$ranges` (plain addresses or CIDR, v4 / v6).
     * A malformed `$ip` never matches, and a malformed range (`10.0.0.0/abc`,
     * `/-1`, `/33`) never matches either — a typo must not become "allow all".
     *
     * @param list<string> $ranges
     */
    public static function ipMatches(string $ip, array $ranges): bool
    {
        $parsed = [];
        foreach ($ranges as $range) {
            $one = self::parseRange($range);
            if (null !== $one) {
                $parsed[] = $one;
            }
        }

        return self::matchesParsed($ip, $parsed);
    }

    /**
     * @param list<array{string, int}> $ranges packed address + prefix length
     */
    private static function matchesParsed(string $ip, array $ranges): bool
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
     * Validate one layer's config into `[header, proxies, trustAnyHop, walkXff]`,
     * with the proxies parsed once (packed address + prefix) so per-request
     * matching is a byte comparison, not a re-parse of every CIDR.
     *
     * @param array{preset?: string, header?: string, proxies?: list<string>, trustAnyHop?: bool} $config
     *
     * @return array{string, list<array{string, int}>, bool, bool}
     */
    private static function layer(array $config): array
    {
        $preset = $config['preset'] ?? null;
        $custom = $config['header'] ?? null;
        $proxies = $config['proxies'] ?? [];
        $trustAnyHop = $config['trustAnyHop'] ?? false;

        if (null !== $preset && null !== $custom) {
            throw new InvalidArgumentException(\sprintf(
                'TrustedProxy: "preset" (%s) and "header" (%s) are mutually exclusive — pass one of them.',
                $preset,
                $custom,
            ));
        }

        $header = match ($preset) {
            null => $custom,
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

        $parsed = [];
        foreach ($proxies as $range) {
            $one = self::parseRange($range);
            if (null === $one) {
                throw new InvalidArgumentException(\sprintf('TrustedProxy: "%s" is not a valid IP address or CIDR range.', $range));
            }
            $parsed[] = $one;
        }
        if ([] === $parsed && !$trustAnyHop) {
            throw new InvalidArgumentException(\sprintf(
                'TrustedProxy: "proxies" is required for the %s header — without a trust list it is client-supplied. '
                . 'Set "trustAnyHop" => true only when the proxy is provably the sole ingress to this process.',
                $header,
            ));
        }

        return [$header, $parsed, $trustAnyHop, self::PRESET_XFF === $preset];
    }

    /**
     * @param list<array{string, list<array{string, int}>, bool, bool}> $layers
     */
    private static function resolve(Request $request, array $layers): ?string
    {
        $hop = $request->ip();

        $resolved = null;
        foreach ($layers as [$header, $proxies, $trustAnyHop, $walkXff]) {
            // The address resolved so far must be one of this layer's
            // proxies, or the layer does not apply and resolution stops:
            // whatever we verified last is the client. `trustAnyHop` does
            // not look at the hop at all — not even at whether there is
            // one (a synthetic Request without REMOTE_ADDR).
            if (!$trustAnyHop && (null === $hop || !self::matchesParsed($hop, $proxies))) {
                break;
            }

            $value = $request->header($header);
            if (null === $value) {
                break;
            }

            $next = $walkXff ? self::walkForwardedFor($value, $proxies) : self::validIp(\trim($value));
            if (null === $next) {
                break;
            }

            $resolved = $hop = $next;
        }

        return $resolved;
    }

    /**
     * X-Forwarded-For: "client, proxy1, proxy2" — each hop appends the
     * address it received from. Walk from the right, past the proxies we
     * trust; the first address that is not ours was put there by a trusted
     * proxy and is the client. Anything further left is client-controlled
     * and ignored. Null when a hop is malformed or every hop is trusted.
     *
     * @param list<array{string, int}> $proxies
     */
    private static function walkForwardedFor(string $value, array $proxies): ?string
    {
        foreach (\array_reverse(\explode(',', $value)) as $hop) {
            $candidate = self::validIp(\trim($hop));
            if (null === $candidate) {
                return null;
            }
            if (!self::matchesParsed($candidate, $proxies)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function validIp(string $value): ?string
    {
        return false === \filter_var($value, \FILTER_VALIDATE_IP) ? null : $value;
    }

    /**
     * `address[/prefix]` → packed address + prefix length, or null when
     * either part is malformed (non-numeric or out-of-range prefix included).
     *
     * @return null|array{string, int}
     */
    private static function parseRange(string $range): ?array
    {
        [$address, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
        $packed = self::pack((string) $address);
        if (null === $packed) {
            return null;
        }
        $maxBits = \strlen($packed) * 8;
        if (null === $bits) {
            return [$packed, $maxBits];
        }
        if (1 !== \preg_match('/^\d{1,3}$/', $bits) || (int) $bits > $maxBits) {
            return null;
        }

        return [$packed, (int) $bits];
    }

    /**
     * Packed binary address: 4 bytes for IPv4, 16 for IPv6. An IPv4-mapped
     * IPv6 address (`::ffff:a.b.c.d`, what a `[::]`-listening server reports
     * for IPv4 peers) is folded to its 4-byte IPv4 form so it matches IPv4
     * ranges.
     */
    private static function pack(string $ip): ?string
    {
        if (false === \filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = \inet_pton($ip);
        if (false === $packed) {
            return null;
        }
        if (16 === \strlen($packed) && \str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            return \substr($packed, 12);
        }

        return $packed;
    }

    /**
     * @param array{string, int} $range packed address + prefix length
     */
    private static function inRange(string $packedIp, array $range): bool
    {
        [$packedRange, $prefix] = $range;
        if (\strlen($packedRange) !== \strlen($packedIp)) {
            return false; // v4 vs v6 never match
        }

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
