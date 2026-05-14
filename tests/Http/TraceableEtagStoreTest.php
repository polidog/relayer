<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\TraceableEtagStore;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class TraceableEtagStoreTest extends TestCase
{
    public function testGetRecordsHitAndMiss(): void
    {
        $inner = new InMemoryEtagStore();
        $inner->set('users/1', '"abc"');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $store = new TraceableEtagStore($inner, $profiler);
        $store->get('users/1');
        $store->get('users/2'); // miss

        $events = $profile->getEvents();
        self::assertCount(2, $events);
        self::assertSame('cache', $events[0]->collector);
        self::assertSame('etag_lookup', $events[0]->label);
        self::assertTrue($events[0]->payload['hit']);
        self::assertFalse($events[1]->payload['hit']);
    }

    public function testSetAndForgetAreRecorded(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $store = new TraceableEtagStore(new InMemoryEtagStore(), $profiler);
        $store->set('users/1', '"v1"');
        $store->forget('users/1');

        $labels = \array_map(static fn ($e): string => $e->label, $profile->getEvents());
        self::assertSame(['etag_write', 'etag_forget'], $labels);
    }

    public function testValuesAreNotRecorded(): void
    {
        // ETag values can be content hashes — fine to log, but session-keyed
        // contents must not leak. Verify the set payload only carries the key.
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $store = new TraceableEtagStore(new InMemoryEtagStore(), $profiler);
        $store->set('users/1', 'secret-etag-value');

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame(['key' => 'users/1'], $events[0]->payload);
    }
}
