<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Closure;
use InvalidArgumentException;
use Polidog\Relayer\Env;
use Polidog\Relayer\Relayer;
use Psr\Log\LoggerInterface;

/**
 * Builds the router guard {@see Relayer::boot()} installs
 * from the environment — the same "set the variable and the framework
 * wires it" convention as `DATABASE_DSN` or `PROFILER_EXCLUDED_PATHS`, so
 * an app behind a CDN needs no PHP in `public/index.php`:
 *
 *   ORIGIN_SECRET=<long random>          # turns OriginGuard on
 *   ORIGIN_SECRET_PREVIOUS=<old>         # accepted too while rotating
 *   ORIGIN_GUARD_HEADER=X-Origin-Secret  # default
 *   ORIGIN_GUARD_EXCEPT=/healthz,/_health/*
 *   ORIGIN_GUARD_MODE=enforce            # or report
 *
 *   TRUSTED_PROXIES=fdaa::/16            # turns TrustedProxy on (CIDR list)
 *   TRUSTED_PROXY_PRESET=fly             # fly | cloudflare | xff (default)
 *   CLOUDFLARE_IP_RANGES=173.245.48.0/20,... # adds a Cloudflare layer behind it
 *
 * Nothing set → no guard. Both set → TrustedProxy runs outside OriginGuard:
 * it never rejects anything, and resolving the client IP first means a
 * rejected bypass attempt is logged with its real source instead of the
 * proxy's address. `AppRouter::setGuard()` replaces whatever this
 * produced, for apps that want to compose their own.
 */
final class GuardFactory
{
    /**
     * @return null|Closure(Request, Closure): void
     */
    public static function fromEnv(?LoggerInterface $logger = null): ?Closure
    {
        $guard = self::originGuard($logger);
        $proxy = self::trustedProxy();

        if (null === $guard) {
            return $proxy;
        }
        if (null === $proxy) {
            return $guard;
        }

        return static fn (Request $request, Closure $next) => $proxy(
            $request,
            static fn (Request $r) => $guard($r, $next),
        );
    }

    /**
     * @return null|Closure(Request, Closure): void
     */
    private static function originGuard(?LoggerInterface $logger): ?Closure
    {
        if (null === Env::read('ORIGIN_SECRET') && null === Env::read('ORIGIN_SECRET_PREVIOUS')) {
            return null;
        }

        $config = [
            'header' => Env::read('ORIGIN_GUARD_HEADER') ?? 'X-Origin-Secret',
            'secretsFromEnv' => ['ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS'],
            'except' => Env::readList('ORIGIN_GUARD_EXCEPT'),
            'mode' => Env::read('ORIGIN_GUARD_MODE') ?? OriginGuard::MODE_ENFORCE,
        ];
        if (null !== $logger) {
            $config['logger'] = $logger;
        }

        return OriginGuard::middleware($config);
    }

    /**
     * @return null|Closure(Request, Closure): void
     */
    private static function trustedProxy(): ?Closure
    {
        $proxies = Env::readList('TRUSTED_PROXIES');
        if ([] === $proxies) {
            return null;
        }

        $preset = Env::read('TRUSTED_PROXY_PRESET') ?? TrustedProxy::PRESET_XFF;
        if (!\in_array($preset, [TrustedProxy::PRESET_FLY, TrustedProxy::PRESET_CLOUDFLARE, TrustedProxy::PRESET_XFF], true)) {
            throw new InvalidArgumentException(\sprintf(
                'TRUSTED_PROXY_PRESET must be "%s", "%s" or "%s", "%s" given.',
                TrustedProxy::PRESET_FLY,
                TrustedProxy::PRESET_CLOUDFLARE,
                TrustedProxy::PRESET_XFF,
                $preset,
            ));
        }

        $layers = [['preset' => $preset, 'proxies' => $proxies]];

        // Cloudflare in front of the proxy above (Cloudflare → Fly): when
        // the address the first layer vouched for is Cloudflare's, the user
        // is in CF-Connecting-IP. Pointless behind a `cloudflare` first
        // layer — put the ranges in TRUSTED_PROXIES then and leave this
        // unset.
        $cloudflare = Env::readList('CLOUDFLARE_IP_RANGES');
        if ([] !== $cloudflare && TrustedProxy::PRESET_CLOUDFLARE !== $preset) {
            $layers[] = ['preset' => TrustedProxy::PRESET_CLOUDFLARE, 'proxies' => $cloudflare];
        }

        return TrustedProxy::middleware(['chain' => $layers]);
    }
}
