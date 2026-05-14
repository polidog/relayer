<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\TraceableSessionStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class TraceableSessionStorageTest extends TestCase
{
    public function testGetSetRemoveRegenerateAreRecorded(): void
    {
        $inner = new ArraySessionStorage();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $session = new TraceableSessionStorage($inner, $profiler);
        $session->set('user', ['id' => 1]);
        $session->get('user');
        $session->get('absent');
        $session->remove('user');
        $session->regenerateId();

        $labels = \array_map(static fn ($e): string => $e->label, $profile->getEvents());
        self::assertSame(['set', 'get', 'get', 'remove', 'regenerate_id'], $labels);

        // Values must never appear — only keys.
        foreach ($profile->getEvents() as $event) {
            self::assertArrayNotHasKey('value', $event->payload);
        }
    }

    public function testGetHitFlagReflectsInnerReturn(): void
    {
        $inner = new ArraySessionStorage();
        $inner->set('present', 'x');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $session = new TraceableSessionStorage($inner, $profiler);
        $session->get('present');
        $session->get('missing');

        $events = $profile->getEvents();
        self::assertTrue($events[0]->payload['hit']);
        self::assertFalse($events[1]->payload['hit']);
    }
}
