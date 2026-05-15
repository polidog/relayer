<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Db;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Db\CachingDatabase;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class CachingDatabaseTest extends TestCase
{
    public function testIdenticalReadHitsCacheAndRecordsHit(): void
    {
        $inner = new FakeDatabase();
        $inner->allResult = [['id' => 1]];

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new CachingDatabase($inner, $profiler);

        $first = $db->fetchAll('SELECT * FROM t WHERE id = :id', ['id' => 1]);
        $second = $db->fetchAll('SELECT * FROM t WHERE id = :id', ['id' => 1]);

        self::assertSame($first, $second);
        self::assertSame(1, $inner->fetchAllCalls, 'inner queried only once');

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('db', $events[0]->collector);
        self::assertSame('cache_hit', $events[0]->label);
        self::assertSame(['sql' => 'SELECT * FROM t WHERE id = :id'], $events[0]->payload);
    }

    public function testDifferentParamsAreNotShared(): void
    {
        $inner = new FakeDatabase();
        $db = new CachingDatabase($inner, new RecordingProfiler());

        $db->fetchAll('SELECT * FROM t WHERE id = :id', ['id' => 1]);
        $db->fetchAll('SELECT * FROM t WHERE id = :id', ['id' => 2]);

        self::assertSame(2, $inner->fetchAllCalls);
    }

    public function testFetchOneAndFetchAllDoNotCollide(): void
    {
        $inner = new FakeDatabase();
        $inner->allResult = [['x' => 1]];
        $inner->oneResult = ['x' => 9];

        $db = new CachingDatabase($inner, new RecordingProfiler());

        $sql = 'SELECT x FROM t';
        self::assertSame([['x' => 1]], $db->fetchAll($sql));
        self::assertSame(['x' => 9], $db->fetchOne($sql));
        self::assertSame(1, $inner->fetchAllCalls);
        self::assertSame(1, $inner->fetchOneCalls);
    }

    public function testWriteFlushesCache(): void
    {
        $inner = new FakeDatabase();
        $db = new CachingDatabase($inner, new RecordingProfiler());

        $db->fetchAll('SELECT * FROM t');
        $db->perform('UPDATE t SET x = 1');
        $db->fetchAll('SELECT * FROM t');

        self::assertSame(2, $inner->fetchAllCalls, 'write invalidated the memoized read');
        self::assertSame(1, $inner->performCalls);
    }

    public function testTransactionalFlushesAndPassesDecoratorToCallback(): void
    {
        $inner = new FakeDatabase();
        $db = new CachingDatabase($inner, new RecordingProfiler());

        $db->fetchAll('SELECT * FROM t');

        $received = $db->transactional(static fn ($tx): object => $tx);

        self::assertSame($db, $received, 'callback receives the caching decorator, not the inner');

        $db->fetchAll('SELECT * FROM t');
        self::assertSame(2, $inner->fetchAllCalls, 'transaction flushed the cache');
    }
}
