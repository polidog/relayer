<?php

declare(strict_types=1);

namespace Polidog\Relayer\Db;

use Polidog\Relayer\Profiler\Profiler;
use Throwable;

/**
 * Dev-only {@see Database} decorator that records every real query into
 * the request-scoped {@see Profiler}.
 *
 * Wired between {@see CachingDatabase} (outer) and {@see PdoDatabase}
 * (inner) in dev only, so it never sees a memoized call — every event it
 * emits is an actual database round-trip. Prod skips this class entirely
 * (the alias points straight at the cache over PDO).
 *
 * Recorded as timed spans: `query` (reads), `mutate` (writes),
 * `transaction` (the whole block). The SQL and bound params are kept in
 * the payload so the timeline shows exactly what ran; on failure the
 * span is still stopped with an `error` message before the exception is
 * rethrown, so a failing query is visible in the profile rather than
 * just vanishing. `lastInsertId()` is intentionally not traced — it is
 * cheap and would only add noise next to the INSERT that precedes it.
 */
final class TraceableDatabase implements Database
{
    public function __construct(
        private readonly Database $inner,
        private readonly Profiler $profiler,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $rows = $this->inner->fetchAll($sql, $params);
            $span->stop(['sql' => $sql, 'params' => $params, 'rows' => \count($rows)]);

            return $rows;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => $params, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $row = $this->inner->fetchOne($sql, $params);
            $span->stop(['sql' => $sql, 'params' => $params, 'rows' => null === $row ? 0 : 1]);

            return $row;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => $params, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $value = $this->inner->fetchValue($sql, $params);
            $span->stop(['sql' => $sql, 'params' => $params]);

            return $value;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => $params, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function perform(string $sql, array $params = []): int
    {
        $span = $this->profiler->start('db', 'mutate');

        try {
            $affected = $this->inner->perform($sql, $params);
            $span->stop(['sql' => $sql, 'params' => $params, 'affected' => $affected]);

            return $affected;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => $params, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->inner->lastInsertId($name);
    }

    public function transactional(callable $callback): mixed
    {
        $span = $this->profiler->start('db', 'transaction');

        try {
            $result = $this->inner->transactional(fn (): mixed => $callback($this));
            $span->stop(['status' => 'commit']);

            return $result;
        } catch (Throwable $e) {
            $span->stop(['status' => 'rollback', 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
