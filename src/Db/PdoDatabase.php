<?php

declare(strict_types=1);

namespace Polidog\Relayer\Db;

use PDO;
use PDOException;
use PDOStatement;
use SensitiveParameter;
use Throwable;

/**
 * {@see Database} on top of PDO.
 *
 * The connection is lazy: nothing connects until the first query, so an
 * app that registers a DSN but never touches the DB on a given request
 * pays no connection cost. Once opened, the PDO handle is reused for the
 * rest of the request (PHP-FPM gives one process per request).
 *
 * Connection options are fixed to fail loudly and predictably:
 * `ERRMODE_EXCEPTION` (every error throws) and a default fetch mode of
 * `FETCH_ASSOC`. A connect timeout (`PDO::ATTR_TIMEOUT`) and, for MySQL,
 * a read timeout (`MYSQL_ATTR_READ_TIMEOUT`) are applied when configured
 * so a stuck DB surfaces as a `DatabaseException` instead of hanging the
 * worker indefinitely.
 *
 * Every PDO failure is caught and rethrown as {@see DatabaseException}
 * with the original `PDOException` kept as the previous exception.
 */
final class PdoDatabase implements Database
{
    private ?PDO $pdo = null;

    /**
     * @param string   $dsn            PDO DSN, e.g. `mysql:host=127.0.0.1;dbname=app`
     * @param null|int $connectTimeout seconds for `PDO::ATTR_TIMEOUT` (connect/handshake)
     * @param null|int $readTimeout    seconds for MySQL `MYSQL_ATTR_READ_TIMEOUT`; ignored
     *                                 for non-MySQL DSNs and when the constant is unavailable
     */
    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        private readonly ?int $connectTimeout = null,
        private readonly ?int $readTimeout = null,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        return \array_values(\array_map(
            self::assoc(...),
            $this->statement($sql, $params)->fetchAll(),
        ));
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch();

        return false === $row ? null : self::assoc($row);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->statement($sql, $params)->fetch(PDO::FETCH_NUM);

        if (!\is_array($row)) {
            return null;
        }

        return $row[0] ?? null;
    }

    public function perform(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): string
    {
        self::assertIdentifier($table, 'table', true);

        if ([] === $data) {
            throw new DatabaseException('insert(): $data must not be empty');
        }

        $columns = \array_keys($data);
        foreach ($columns as $column) {
            self::assertIdentifier((string) $column, 'column', false);
        }

        $placeholders = \implode(', ', \array_fill(0, \count($columns), '?'));
        $sql = 'INSERT INTO ' . $table
            . ' (' . \implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        $this->perform($sql, \array_values($data));

        return $this->lastInsertId();
    }

    public function update(string $table, array $set, array $where): int
    {
        self::assertIdentifier($table, 'table', true);

        if ([] === $set) {
            throw new DatabaseException('update(): $set must not be empty');
        }
        if ([] === $where) {
            throw new DatabaseException(
                'update(): $where must not be empty — use perform() for an unfiltered UPDATE',
            );
        }

        $assignments = [];
        foreach (\array_keys($set) as $column) {
            self::assertIdentifier((string) $column, 'column', false);
            $assignments[] = $column . ' = ?';
        }

        [$whereSql, $whereParams] = self::buildWhere($where);

        $sql = 'UPDATE ' . $table . ' SET ' . \implode(', ', $assignments)
            . ' WHERE ' . $whereSql;

        return $this->perform($sql, [...\array_values($set), ...$whereParams]);
    }

    public function delete(string $table, array $where): int
    {
        self::assertIdentifier($table, 'table', true);

        if ([] === $where) {
            throw new DatabaseException(
                'delete(): $where must not be empty — use perform() to delete every row',
            );
        }

        [$whereSql, $whereParams] = self::buildWhere($where);

        return $this->perform('DELETE FROM ' . $table . ' WHERE ' . $whereSql, $whereParams);
    }

    public function lastInsertId(?string $name = null): string
    {
        try {
            $id = $this->pdo()->lastInsertId($name);
        } catch (PDOException $e) {
            throw new DatabaseException('lastInsertId failed: ' . $e->getMessage(), 0, $e);
        }

        return false === $id ? '' : $id;
    }

    public function transactional(callable $callback): mixed
    {
        $pdo = $this->pdo();

        try {
            $pdo->beginTransaction();
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack($pdo, $e);

            if ($e instanceof PDOException) {
                throw new DatabaseException('Transaction failed: ' . $e->getMessage(), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Roll back the open transaction. A rollback can itself fail (e.g. the
     * connection dropped mid-transaction); that must not escape unwrapped
     * and must not mask the original failure, so it surfaces as a
     * {@see DatabaseException} that chains `$original` as the previous
     * exception (the actionable root cause for the caller).
     */
    private function rollBack(PDO $pdo, Throwable $original): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->rollBack();
        } catch (PDOException $rollbackError) {
            throw new DatabaseException(
                'Transaction rollback failed: ' . $rollbackError->getMessage()
                    . ' (original error: ' . $original->getMessage() . ')',
                0,
                $original,
            );
        }
    }

    /**
     * Narrow one fetched row to a string-keyed map. Rows come back under
     * `FETCH_ASSOC` so keys are already column-name strings — this just
     * makes that guarantee explicit to the type system (and drops the odd
     * non-string key rather than asserting it away). Non-arrays collapse
     * to an empty row.
     *
     * @return array<string, mixed>
     */
    private static function assoc(mixed $row): array
    {
        if (!\is_array($row)) {
            return [];
        }

        $out = [];
        foreach ($row as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Build the `WHERE` fragment and its positional params for the DML
     * helpers. Equality only, `AND`-combined; `null` maps to `IS NULL`
     * because `col = ?` bound with null never matches in SQL — a
     * silent-no-match trap.
     *
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhere(array $where): array
    {
        $clauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            self::assertIdentifier((string) $column, 'column', false);

            if (null === $value) {
                $clauses[] = $column . ' IS NULL';

                continue;
            }

            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }

        return [\implode(' AND ', $clauses), $params];
    }

    /**
     * Reject anything that is not a plain SQL identifier so a column or
     * table name from a DML helper can never carry SQL (values are always
     * bound, so only the non-parameterizable parts need this guard).
     *
     * @throws DatabaseException
     */
    private static function assertIdentifier(string $identifier, string $kind, bool $allowQualifier): void
    {
        $pattern = $allowQualifier
            ? '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/'
            : '/^[A-Za-z_][A-Za-z0-9_]*$/';

        if (1 !== \preg_match($pattern, $identifier)) {
            throw new DatabaseException(\sprintf(
                'Invalid %s identifier %s — use perform() for quoted/expression identifiers',
                $kind,
                '"' . $identifier . '"',
            ));
        }
    }

    /**
     * Prepare + execute, normalizing any driver failure into
     * {@see DatabaseException}.
     *
     * @param array<int|string, mixed> $params
     */
    private function statement(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute([] === $params ? null : $params);

            return $stmt;
        } catch (PDOException $e) {
            throw new DatabaseException('Query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function pdo(): PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }

        try {
            return $this->pdo = new PDO(
                $this->dsn,
                $this->username,
                $this->password,
                $this->connectionOptions(),
            );
        } catch (PDOException $e) {
            throw new DatabaseException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, int>
     */
    private function connectionOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (null !== $this->connectTimeout) {
            $options[PDO::ATTR_TIMEOUT] = $this->connectTimeout;
        }

        // MYSQL_ATTR_READ_TIMEOUT only exists when pdo_mysql is loaded;
        // referencing it unconditionally would be a fatal on other builds.
        // constant() yields mixed since the constant is driver-provided —
        // narrow to int before using it as an array key.
        if (null !== $this->readTimeout
            && \str_starts_with($this->dsn, 'mysql:')
            && \defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $readTimeoutAttr = \constant('PDO::MYSQL_ATTR_READ_TIMEOUT');
            if (\is_int($readTimeoutAttr)) {
                $options[$readTimeoutAttr] = $this->readTimeout;
            }
        }

        return $options;
    }
}
