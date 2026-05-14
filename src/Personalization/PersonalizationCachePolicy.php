<?php

declare(strict_types=1);

namespace Polidog\Relayer\Personalization;

use InvalidArgumentException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\CachePolicy;

/**
 * Cache-header policy for personalization fragment responses.
 *
 * The whole point of the primitive is that the wrapping page can be
 * `Cache-Control: public, s-maxage=...` and served from a shared CDN —
 * which only works if the personalize fragment itself never leaks into the
 * shared cache. So the framework enforces a private policy on every
 * `/_relayer/personalize/{id}` response, and rejects handler-supplied
 * `Cache` values that would relax that.
 */
final class PersonalizationCachePolicy
{
    /**
     * Default policy applied when the handler doesn't call
     * `$ctx->cache(...)`. `private, no-store` + `Vary: Cookie` is the
     * tight default — handlers can opt into `private, max-age=N` for a
     * short browser cache by passing their own Cache.
     */
    public static function defaultCache(): Cache
    {
        return new Cache(
            private: true,
            noStore: true,
            vary: ['Cookie'],
        );
    }

    /**
     * Throws when `$cache` would let a personalized response land in a
     * shared cache. Specifically: `public` is forbidden; `sMaxAge` is
     * forbidden because it controls shared-cache freshness.
     */
    public static function assertSafe(Cache $cache): void
    {
        if ($cache->public) {
            throw new InvalidArgumentException(
                'Personalization cache policy cannot be public — '
                . 'fragment responses must not enter shared caches.',
            );
        }

        if (null !== $cache->sMaxAge) {
            throw new InvalidArgumentException(
                'Personalization cache policy cannot set sMaxAge — '
                . 'fragment responses must not enter shared caches.',
            );
        }
    }

    /**
     * Validate (when non-null) and emit the cache headers for a fragment
     * response. Falls back to `defaultCache()` when the handler didn't
     * declare one.
     */
    public static function apply(?Cache $userCache): void
    {
        $cache = $userCache ?? self::defaultCache();

        if (null !== $userCache) {
            self::assertSafe($userCache);
        }

        CachePolicy::emit($cache);
    }
}
