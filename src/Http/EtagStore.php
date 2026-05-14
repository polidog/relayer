<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Key/value storage for ETag validators.
 *
 * Implementations let pages declare `#[Cache(etagKey: '...')]` and have the
 * framework resolve the actual ETag value from a fast backing store (file,
 * Redis, etc.) before the page is instantiated. This lets the framework
 * answer conditional GETs with `304 Not Modified` without ever touching the
 * underlying database / domain layer.
 *
 * Producers (repositories, command handlers) call `set()` when the data they
 * own changes — typically with a hash of the new content (`sha1(...)`) or a
 * version stamp.
 *
 * Keys are arbitrary strings; implementations are responsible for safely
 * mapping them onto the underlying medium.
 */
interface EtagStore
{
    /**
     * Returns the stored ETag value for $key, or null when nothing is stored.
     */
    public function get(string $key): ?string;

    /**
     * Replaces (or creates) the stored ETag value for $key.
     */
    public function set(string $key, string $etag): void;

    /**
     * Removes any stored value for $key. Idempotent: noop if absent.
     */
    public function forget(string $key): void;
}
