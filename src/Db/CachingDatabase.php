<?php

declare(strict_types=1);

namespace Polidog\Relayer\Db;

use Polidog\Relayer\Profiler\Profiler;

/**
 * Request-scoped read memoization for {@see Database}.
 *
 * The same logical page is often assembled from several components that
 * each need the same lookup (the current user, a settings row, …). Without
 * memoization that is N identical round-trips per request. This decorator
 * caches read results keyed by `(method, sql, params)` for the lifetime of
 * the request only — it is a plain in-process array, never persisted, and
 * dies with the worker. There is no TTL and no cross-request sharing by
 * design; that would need invalidation/serialization machinery this layer
 * deliberately avoids.
 *
 * Any write (`perform`) or `transactional` block clears the whole cache:
 * a request that reads then writes then reads again must see its own
 * write, and a blunt full flush is simpler and safe at request scope.
 *
 * This is the OUTERMOST decorator (it wraps {@see TraceableDatabase} in
 * dev, {@see PdoDatabase} directly in prod). So a cache hit short-circuits
 * before the traceable layer: the hit is recorded once as a `db.cache_hit`
 * event and produces no `db.query` span, keeping the profiler timeline
 * showing real round-trips as spans and saved ones as hit markers.
 */
final class CachingDatabase implements Database
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $allCache = [];

    /** @var array<string, null|array<string, mixed>> */
    private array $oneCache = [];

    /** @var array<string, mixed> */
    private array $valueCache = [];

    public function __construct(
        private readonly Database $inner,
        private readonly Profiler $profiler,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $key = $sql . '|' . \serialize($params);

        if (\array_key_exists($key, $this->allCache)) {
            $this->profiler->collect('db', 'cache_hit', ['sql' => $sql]);

            return $this->allCache[$key];
        }

        return $this->allCache[$key] = $this->inner->fetchAll($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $key = $sql . '|' . \serialize($params);

        if (\array_key_exists($key, $this->oneCache)) {
            $this->profiler->collect('db', 'cache_hit', ['sql' => $sql]);

            return $this->oneCache[$key];
        }

        return $this->oneCache[$key] = $this->inner->fetchOne($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $key = $sql . '|' . \serialize($params);

        if (\array_key_exists($key, $this->valueCache)) {
            $this->profiler->collect('db', 'cache_hit', ['sql' => $sql]);

            return $this->valueCache[$key];
        }

        return $this->valueCache[$key] = $this->inner->fetchValue($sql, $params);
    }

    public function perform(string $sql, array $params = []): int
    {
        $this->flush();

        return $this->inner->perform($sql, $params);
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->inner->lastInsertId($name);
    }

    public function transactional(callable $callback): mixed
    {
        $this->flush();

        return $this->inner->transactional(fn (): mixed => $callback($this));
    }

    private function flush(): void
    {
        $this->allCache = [];
        $this->oneCache = [];
        $this->valueCache = [];
    }
}
