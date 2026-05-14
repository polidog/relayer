<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Declare HTTP cache policy on a Page class.
 *
 * The framework reads this attribute when AppRouter resolves the page through
 * the container and emits matching Cache-Control / Vary / ETag / Last-Modified
 * headers before the response body is written. It also honors `If-None-Match`
 * and `If-Modified-Since` on GET/HEAD requests, short-circuiting the response
 * with `304 Not Modified` when the client already has a fresh copy.
 *
 * Attach only to PageComponent subclasses — non-page services with this
 * attribute are ignored to avoid surprising header writes.
 *
 * @example
 *   #[Cache(maxAge: 3600, public: true, etag: 'home-v1')]
 *   final class HomePage extends PageComponent {}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Cache
{
    /**
     * @param int|null      $maxAge          `max-age` directive (seconds).
     * @param int|null      $sMaxAge         `s-maxage` directive (seconds, shared/CDN cache).
     * @param bool          $public          Emit `public` directive.
     * @param bool          $private         Emit `private` directive.
     * @param bool          $noStore         Emit `no-store`.
     * @param bool          $noCache         Emit `no-cache`.
     * @param bool          $mustRevalidate  Emit `must-revalidate`.
     * @param bool          $immutable       Emit `immutable`.
     * @param string[]      $vary            Values for the `Vary` header.
     * @param string|null   $etag            Literal ETag value (raw or already quoted).
     * @param bool          $etagWeak        Emit ETag as a weak validator (`W/"…"`).
     * @param string|null   $lastModified    `Last-Modified` value (anything `strtotime()` accepts; UTC recommended).
     * @param string|null   $etagKey         Logical key looked up against the configured `EtagStore`.
     *                                       Static `etag` takes precedence when both are set.
     */
    public function __construct(
        public readonly ?int $maxAge = null,
        public readonly ?int $sMaxAge = null,
        public readonly bool $public = false,
        public readonly bool $private = false,
        public readonly bool $noStore = false,
        public readonly bool $noCache = false,
        public readonly bool $mustRevalidate = false,
        public readonly bool $immutable = false,
        public readonly array $vary = [],
        public readonly ?string $etag = null,
        public readonly bool $etagWeak = false,
        public readonly ?string $lastModified = null,
        public readonly ?string $etagKey = null,
    ) {
    }
}
