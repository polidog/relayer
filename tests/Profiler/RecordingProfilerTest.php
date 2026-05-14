<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Profile;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class RecordingProfilerTest extends TestCase
{
    public function testCollectAppendsEventToCurrentProfile(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/users', 'GET');

        $profiler->collect('route', 'match', ['pattern' => '/users']);
        $profiler->collect('page', 'load', ['kind' => 'function']);

        $events = $profile->getEvents();
        self::assertCount(2, $events);
        self::assertSame('route', $events[0]->collector);
        self::assertSame('match', $events[0]->label);
        self::assertSame(['pattern' => '/users'], $events[0]->payload);
        self::assertNull($events[0]->durationMs);
    }

    public function testStartProducesTimedEvent(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $span = $profiler->start('page', 'render');
        \usleep(2000); // ~2ms so the duration is non-zero on any clock
        $span->stop(['componentId' => 'page:/']);

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('page', $event->collector);
        self::assertSame('render', $event->label);
        self::assertSame(['componentId' => 'page:/'], $event->payload);
        self::assertNotNull($event->durationMs);
        self::assertGreaterThan(0.0, $event->durationMs);
    }

    public function testCollectBeforeBeginProfileIsSilent(): void
    {
        $profiler = new RecordingProfiler();
        // No beginProfile() — user code that calls $profiler->collect outside
        // the dispatch window must not throw.
        $profiler->collect('db', 'query', ['sql' => 'SELECT 1']);

        self::assertNull($profiler->currentProfile());
    }

    public function testEndProfileFinalizesAndPersists(): void
    {
        $storage = new class implements ProfilerStorage {
            public ?Profile $saved = null;

            public function save(Profile $profile): void
            {
                $this->saved = $profile;
            }

            public function load(string $token): ?Profile
            {
                return null;
            }

            public function recent(int $limit = 20): array
            {
                return [];
            }
        };

        $profiler = new RecordingProfiler($storage);
        $profile = $profiler->beginProfile('/x', 'POST');
        $profiler->collect('route', 'match', []);
        $profiler->endProfile(201);

        self::assertSame($profile, $storage->saved);
        self::assertSame(201, $profile->getStatusCode());
        self::assertNotNull($profile->getEndedAt());
        self::assertNotNull($profile->durationMs());
    }

    public function testStopIsIdempotent(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $span = $profiler->start('cache', 'apply');
        $span->stop();
        $span->stop(['ignored' => true]);

        self::assertCount(1, $profile->getEvents());
    }
}
