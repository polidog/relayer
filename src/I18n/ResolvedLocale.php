<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

/**
 * Result of {@see LocaleResolver::resolve()}.
 *
 * `$path` is the request path the router should match on: identical to the
 * incoming path for every source except `path`, where the leading
 * `/{locale}` segment has been stripped (so `/ja/about` routes to the same
 * page file as `/about`).
 */
final readonly class ResolvedLocale
{
    /**
     * @param string $locale resolved (canonical) locale code
     * @param string $path   path to route on (locale prefix stripped)
     * @param string $source one of: path, session, cookie, accept-language, default
     */
    public function __construct(
        public string $locale,
        public string $path,
        public string $source,
    ) {}
}
