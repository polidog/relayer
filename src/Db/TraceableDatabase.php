<?php

declare(strict_types=1);

namespace Polidog\Relayer\Db;

use Polidog\Relayer\Auth\TraceableAuthenticator;
use Polidog\Relayer\Http\TraceableEtagStore;
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
    /**
     * Truncation threshold for string param values. Long blobs (CSV
     * uploads, serialized payloads) bound into a query would otherwise
     * bloat the JSON profile.
     */
    private const int MAX_VALUE_LEN = 120;

    public function __construct(
        private readonly Database $inner,
        private readonly Profiler $profiler,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $rows = $this->inner->fetchAll($sql, $params);
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'rows' => \count($rows)]);

            return $rows;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $row = $this->inner->fetchOne($sql, $params);
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'rows' => null === $row ? 0 : 1]);

            return $row;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $span = $this->profiler->start('db', 'query');

        try {
            $value = $this->inner->fetchValue($sql, $params);
            $span->stop(['sql' => $sql, 'params' => self::redact($params)]);

            return $value;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function perform(string $sql, array $params = []): int
    {
        $span = $this->profiler->start('db', 'mutate');

        try {
            $affected = $this->inner->perform($sql, $params);
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'affected' => $affected]);

            return $affected;
        } catch (Throwable $e) {
            $span->stop(['sql' => $sql, 'params' => self::redact($params), 'error' => $e->getMessage()]);

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

    /**
     * Sanitize bound params before they enter the profile. Profiles are
     * written as plain JSON under `var/cache/profiler/`, so a secret bound
     * into a query (a password being hashed, an API token in a WHERE)
     * must not be recorded verbatim — the same reason
     * {@see TraceableAuthenticator} never logs the
     * password and {@see TraceableEtagStore} logs
     * keys but not values.
     *
     * Values under a sensitive-looking key are masked; over-long strings
     * are truncated; everything else is kept so the timeline stays useful
     * for debugging.
     *
     * @param array<int|string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    private static function redact(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if (\is_string($key) && 1 === \preg_match('/pass|pwd|secret|token|api[-_]?key|auth/i', $key)) {
                $out[$key] = '***';

                continue;
            }

            if (\is_string($value) && \strlen($value) > self::MAX_VALUE_LEN) {
                $out[$key] = \substr($value, 0, self::MAX_VALUE_LEN) . '… (' . \strlen($value) . ' bytes)';

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
