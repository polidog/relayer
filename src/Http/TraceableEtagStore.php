<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use Polidog\Relayer\Profiler\Profiler;

/**
 * Dev-only {@see EtagStore} decorator that records each operation into
 * the request-scoped {@see Profiler}. Useful for spotting unexpected
 * etag misses or chatty pages that hit the store multiple times per
 * request.
 *
 * Values are NOT recorded — only keys and outcome — so the timeline
 * stays compact and avoids leaking sensitive content into JSON dumps.
 */
final class TraceableEtagStore implements EtagStore
{
    public function __construct(
        private readonly EtagStore $inner,
        private readonly Profiler $profiler,
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->inner->get($key);
        $this->profiler->collect('cache', 'etag_lookup', [
            'key' => $key,
            'hit' => null !== $value && '' !== $value,
        ]);

        return $value;
    }

    public function set(string $key, string $etag): void
    {
        $this->inner->set($key, $etag);
        $this->profiler->collect('cache', 'etag_write', [
            'key' => $key,
        ]);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
        $this->profiler->collect('cache', 'etag_forget', [
            'key' => $key,
        ]);
    }
}
