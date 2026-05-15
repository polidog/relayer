<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Db;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Db\DatabaseException;
use Polidog\Relayer\Db\TraceableDatabase;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class TraceableDatabaseTest extends TestCase
{
    public function testReadRecordsQueryEventWithRowCount(): void
    {
        $inner = new FakeDatabase();
        $inner->allResult = [['id' => 1], ['id' => 2]];

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);
        $db->fetchAll('SELECT * FROM t WHERE a = :a', ['a' => 7]);

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('db', $events[0]->collector);
        self::assertSame('query', $events[0]->label);
        self::assertSame('SELECT * FROM t WHERE a = :a', $events[0]->payload['sql']);
        self::assertSame(['a' => 7], $events[0]->payload['params']);
        self::assertSame(2, $events[0]->payload['rows']);
        self::assertNotNull($events[0]->durationMs);
    }

    public function testSensitiveParamsAreRedactedAndLongValuesTruncated(): void
    {
        $inner = new FakeDatabase();

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);
        $db->perform(
            'UPDATE users SET password = :password, note = :note WHERE id = :id',
            [
                'password' => 'super-secret-hash',
                'note' => \str_repeat('x', 500),
                'id' => 7,
            ],
        );

        $params = $profile->getEvents()[0]->payload['params'];
        self::assertIsArray($params);
        self::assertSame('***', $params['password']);
        self::assertSame(7, $params['id']);
        self::assertIsString($params['note']);
        self::assertStringEndsWith('(500 bytes)', $params['note']);
        self::assertLessThan(500, \strlen($params['note']));
    }

    public function testWriteRecordsMutateEventWithAffected(): void
    {
        $inner = new FakeDatabase();
        $inner->affected = 3;

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);
        $db->perform('DELETE FROM t');

        $events = $profile->getEvents();
        self::assertSame('mutate', $events[0]->label);
        self::assertSame(3, $events[0]->payload['affected']);
    }

    public function testTransactionRecordsCommit(): void
    {
        $inner = new FakeDatabase();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);
        $db->transactional(static fn (): string => 'ok');

        $events = $profile->getEvents();
        self::assertSame('transaction', $events[0]->label);
        self::assertSame(['status' => 'commit'], $events[0]->payload);
    }

    public function testFailedQueryIsRecordedThenRethrown(): void
    {
        $inner = new FakeDatabase();
        $inner->throw = new DatabaseException('connection lost');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);

        try {
            $db->fetchAll('SELECT 1');
            self::fail('expected DatabaseException');
        } catch (DatabaseException $e) {
            self::assertSame('connection lost', $e->getMessage());
        }

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('query', $events[0]->label);
        self::assertSame('connection lost', $events[0]->payload['error']);
    }

    public function testFailedTransactionRecordsRollback(): void
    {
        $inner = new FakeDatabase();
        $inner->throw = new DatabaseException('deadlock');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $db = new TraceableDatabase($inner, $profiler);

        try {
            $db->transactional(static fn (): string => 'never');
            self::fail('expected DatabaseException');
        } catch (DatabaseException) {
        }

        $events = $profile->getEvents();
        self::assertSame('transaction', $events[0]->label);
        self::assertSame('rollback', $events[0]->payload['status']);
        self::assertSame('deadlock', $events[0]->payload['error']);
    }
}
