<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

/**
 * What kind of handler backs a {@see Route} — i.e. which dispatch pipeline
 * a match goes down.
 *
 * Backed by a string so the value round-trips through the compiled-routes
 * artifact as-is (`'kind' => 'native'`) and reads in `relayer routes`'
 * TYPE column without a mapping table.
 */
enum RouteKind: string
{
    /** `page.psx` / `page.php` — rendered through the layout/document pipeline. */
    case Page = 'page';

    /** `route.php` — a method-keyed map of autowired PHP handlers returning a `Response`. */
    case Api = 'api';

    /**
     * `route.go` — a handler written in another language, compiled by
     * `relayer native:build` and run as a CGI process per request. Go is
     * the only language today; the kind is language-neutral on purpose so a
     * `route.rs` can join later without touching the router.
     */
    case Native = 'native';

    /**
     * The kind a pre-`kind` route implies — the compiled artifact and the
     * `Route` constructor both carried only a boolean before `Native`
     * existed.
     */
    public static function fromLegacyFlag(bool $isApi): self
    {
        return $isApi ? self::Api : self::Page;
    }

    /**
     * True for every kind that answers with data rather than a rendered
     * page: no layouts, no HTML error page, JSON on failure.
     */
    public function isEndpoint(): bool
    {
        return self::Page !== $this;
    }
}
