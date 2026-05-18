<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Db\CachingDatabase;
use Polidog\Relayer\Db\DatabaseException;
use Polidog\Relayer\Db\PdoDatabase;
use Polidog\Relayer\Db\TraceableDatabase;
use Polidog\Relayer\Profiler\RecordingProfiler;

/**
 * The insert/update/delete contract, exercised through the real driver
 * and through the prod/dev decorator chain (the SQL is built once in
 * {@see PdoDatabase}; the decorators forward while applying their own
 * concern).
 */
final class DmlTest extends TestCase
{
    public function testInsertUpdateDeleteRoundTripOnRealDriver(): void
    {
        $db = $this->sqlite();

        $id = $db->insert('users', ['name' => 'Alice', 'age' => 30]);
        self::assertSame('1', $id);
        self::assertSame(
            ['name' => 'Alice', 'age' => 30],
            $db->fetchOne('SELECT name, age FROM users WHERE id = ?', [$id]),
        );

        self::assertSame(1, $db->update('users', ['age' => 31], ['id' => $id]));
        self::assertSame(31, $db->fetchValue('SELECT age FROM users WHERE id = ?', [$id]));

        self::assertSame(1, $db->delete('users', ['id' => $id]));
        self::assertNull($db->fetchOne('SELECT id FROM users WHERE id = ?', [$id]));
    }

    public function testNullInWhereBecomesIsNull(): void
    {
        $db = $this->sqlite();
        $db->insert('users', ['name' => 'NoAge', 'age' => null]);
        $db->insert('users', ['name' => 'HasAge', 'age' => 5]);

        self::assertSame(1, $db->update('users', ['name' => 'Found'], ['age' => null]));
        self::assertSame('Found', $db->fetchValue('SELECT name FROM users WHERE age IS NULL'));
    }

    public function testMultiColumnWhereIsAndCombined(): void
    {
        $db = $this->sqlite();
        $db->insert('users', ['name' => 'Bob', 'age' => 41]);
        $db->insert('users', ['name' => 'Bob', 'age' => 20]);

        self::assertSame(1, $db->delete('users', ['name' => 'Bob', 'age' => 41]));
        self::assertSame(
            [['name' => 'Bob', 'age' => 20]],
            $db->fetchAll('SELECT name, age FROM users'),
        );
    }

    /**
     * Prod chain (Caching over Pdo): a helper write must flush the read
     * cache so a subsequent identical read sees the write.
     */
    public function testHelperWriteFlushesCachingDecorator(): void
    {
        $db = new CachingDatabase(
            new PdoDatabase('sqlite::memory:'),
            new RecordingProfiler(),
        );
        $db->perform('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n TEXT)');

        $db->insert('t', ['n' => 'first']);
        self::assertSame(1, $db->fetchValue('SELECT COUNT(*) FROM t'));

        $db->insert('t', ['n' => 'second']); // must bust the cached COUNT
        self::assertSame(2, $db->fetchValue('SELECT COUNT(*) FROM t'));

        $db->update('t', ['n' => 'x'], ['n' => 'first']);
        self::assertSame(1, $db->fetchValue("SELECT COUNT(*) FROM t WHERE n = 'x'"));

        $db->delete('t', ['n' => 'x']);
        self::assertSame(1, $db->fetchValue('SELECT COUNT(*) FROM t'));
    }

    public function testCachingDecoratorForwardsHelpersToInner(): void
    {
        $inner = new FakeDatabase();
        $inner->insertId = '7';
        $db = new CachingDatabase($inner, new RecordingProfiler());

        self::assertSame('7', $db->insert('t', ['a' => 1]));
        $db->update('t', ['a' => 2], ['id' => 1]);
        $db->delete('t', ['id' => 1]);

        // FakeDatabase routes each helper through its own perform().
        self::assertSame(3, $inner->performCalls);
    }

    public function testTraceableRecordsHelperWritesAsMutateSpans(): void
    {
        $raw = new PdoDatabase('sqlite::memory:');
        $raw->perform('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');

        // Wrap the same connection only now, so the recorded spans are the
        // helper writes alone (no CREATE TABLE noise).
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'POST');
        $db = new TraceableDatabase($raw, $profiler);

        $id = $db->insert('users', ['name' => 'Trace', 'age' => 1]);
        $db->update('users', ['age' => 2], ['id' => $id]);
        $db->delete('users', ['id' => $id]);

        $events = $profile->getEvents();
        self::assertCount(3, $events);
        self::assertSame(['db', 'mutate', 'insert', 'users'], [
            $events[0]->collector,
            $events[0]->label,
            $events[0]->payload['op'],
            $events[0]->payload['table'],
        ]);
        self::assertSame('update', $events[1]->payload['op']);
        self::assertSame(1, $events[1]->payload['affected']);
        self::assertSame('delete', $events[2]->payload['op']);
    }

    /**
     * @param callable(PdoDatabase): mixed $call
     */
    #[DataProvider('provideInvalidCallsThrowDatabaseExceptionCases')]
    public function testInvalidCallsThrowDatabaseException(callable $call, string $expectedMessage): void
    {
        $db = $this->sqlite();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($expectedMessage);

        $call($db);
    }

    /**
     * @return iterable<string, array{0: callable(PdoDatabase): mixed, 1: string}>
     */
    public static function provideInvalidCallsThrowDatabaseExceptionCases(): iterable
    {
        yield 'empty insert data' => [
            static fn (PdoDatabase $db) => $db->insert('users', []),
            'must not be empty',
        ];

        yield 'empty update set' => [
            static fn (PdoDatabase $db) => $db->update('users', [], ['id' => 1]),
            'must not be empty',
        ];

        yield 'empty update where' => [
            static fn (PdoDatabase $db) => $db->update('users', ['age' => 1], []),
            'unfiltered UPDATE',
        ];

        yield 'empty delete where' => [
            static fn (PdoDatabase $db) => $db->delete('users', []),
            'delete every row',
        ];

        yield 'injection in table' => [
            static fn (PdoDatabase $db) => $db->insert('users; DROP TABLE users', ['a' => 1]),
            'Invalid table identifier',
        ];

        yield 'injection in column' => [
            static fn (PdoDatabase $db) => $db->insert('users', ['a = 1 OR 1=1' => 1]),
            'Invalid column identifier',
        ];

        yield 'injection in where column' => [
            static fn (PdoDatabase $db) => $db->delete('users', ['1=1 --' => 1]),
            'Invalid column identifier',
        ];

        // The table identifier is validated before the empty-arg guards,
        // so a bad table never gets masked by a "must not be empty" error.
        yield 'invalid table beats empty insert data' => [
            static fn (PdoDatabase $db) => $db->insert('bad table', []),
            'Invalid table identifier',
        ];

        yield 'invalid table beats empty delete where' => [
            static fn (PdoDatabase $db) => $db->delete('bad table', []),
            'Invalid table identifier',
        ];
    }

    private function sqlite(): PdoDatabase
    {
        $db = new PdoDatabase('sqlite::memory:');
        $db->perform('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');

        return $db;
    }
}
