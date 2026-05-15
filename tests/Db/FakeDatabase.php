<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Db;

use Polidog\Relayer\Db\Database;
use Throwable;

/**
 * Scriptable {@see Database} test double.
 *
 * Counts how many times each read/write method is invoked (so caching
 * behavior can be asserted), returns canned results, and can be told to
 * throw so error paths in the decorators are exercised.
 *
 * Not named `*Test`, so PHPUnit skips it; PSR-4 autoload still loads it.
 */
final class FakeDatabase implements Database
{
    public int $fetchAllCalls = 0;

    public int $fetchOneCalls = 0;

    public int $fetchValueCalls = 0;

    public int $performCalls = 0;

    public int $transactionalCalls = 0;

    public ?Throwable $throw = null;

    /** @var list<array<string, mixed>> */
    public array $allResult = [];

    /** @var null|array<string, mixed> */
    public ?array $oneResult = null;

    public mixed $valueResult = null;

    public int $affected = 0;

    public string $insertId = '';

    public function fetchAll(string $sql, array $params = []): array
    {
        ++$this->fetchAllCalls;
        $this->maybeThrow();

        return $this->allResult;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        ++$this->fetchOneCalls;
        $this->maybeThrow();

        return $this->oneResult;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        ++$this->fetchValueCalls;
        $this->maybeThrow();

        return $this->valueResult;
    }

    public function perform(string $sql, array $params = []): int
    {
        ++$this->performCalls;
        $this->maybeThrow();

        return $this->affected;
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->insertId;
    }

    public function transactional(callable $callback): mixed
    {
        ++$this->transactionalCalls;
        $this->maybeThrow();

        return $callback($this);
    }

    private function maybeThrow(): void
    {
        if (null !== $this->throw) {
            throw $this->throw;
        }
    }
}
