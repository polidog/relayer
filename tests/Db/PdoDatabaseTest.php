<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Db;

use PDOException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Db\DatabaseException;
use Polidog\Relayer\Db\PdoDatabase;
use RuntimeException;

final class PdoDatabaseTest extends TestCase
{
    public function testCrudRoundTrip(): void
    {
        $db = $this->db();

        $affected = $db->perform(
            'INSERT INTO users (name, age) VALUES (:name, :age)',
            ['name' => 'Alice', 'age' => 30],
        );
        self::assertSame(1, $affected);
        self::assertSame('1', $db->lastInsertId());

        $db->perform('INSERT INTO users (name, age) VALUES (?, ?)', ['Bob', 41]);

        $all = $db->fetchAll('SELECT name, age FROM users ORDER BY id');
        self::assertSame([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 41],
        ], $all);

        $one = $db->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => 2]);
        self::assertSame(['name' => 'Bob'], $one);

        $value = $db->fetchValue('SELECT age FROM users WHERE name = ?', ['Alice']);
        self::assertSame(30, $value);
    }

    public function testFetchOneReturnsNullWhenNoRow(): void
    {
        $db = $this->db();

        self::assertNull($db->fetchOne('SELECT id FROM users WHERE id = 999'));
        self::assertNull($db->fetchValue('SELECT id FROM users WHERE id = 999'));
        self::assertSame([], $db->fetchAll('SELECT id FROM users WHERE id = 999'));
    }

    public function testInvalidSqlIsWrappedInDatabaseException(): void
    {
        $db = $this->db();

        try {
            $db->fetchAll('SELECT * FROM does_not_exist');
            self::fail('expected DatabaseException');
        } catch (DatabaseException $e) {
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
        }
    }

    public function testTransactionalCommits(): void
    {
        $db = $this->db();

        $result = $db->transactional(static function ($tx): string {
            $tx->perform('INSERT INTO users (name, age) VALUES (?, ?)', ['Carol', 22]);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testTransactionalRollsBackAndRethrows(): void
    {
        $db = $this->db();

        try {
            $db->transactional(static function ($tx): void {
                $tx->perform('INSERT INTO users (name, age) VALUES (?, ?)', ['Dave', 50]);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // If the rollback failed (or the exception didn't propagate and the
        // insert committed) this count would be 1.
        self::assertSame(0, $db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testConnectionFailureIsWrapped(): void
    {
        // A non-existent sqlite directory makes the lazy connect fail on
        // first use rather than at construction.
        $db = new PdoDatabase('sqlite:/no/such/dir/db.sqlite');

        $this->expectException(DatabaseException::class);
        $db->fetchAll('SELECT 1');
    }

    private function db(): PdoDatabase
    {
        $db = new PdoDatabase('sqlite::memory:');
        $db->perform('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');

        return $db;
    }
}
