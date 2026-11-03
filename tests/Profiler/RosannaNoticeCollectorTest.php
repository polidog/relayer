<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Profiler\RosannaNoticeCollector;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderer;

#[CoversClass(RosannaNoticeCollector::class)]
final class RosannaNoticeCollectorTest extends TestCase
{
    /** @var list<string> */
    private array $forwarded = [];

    protected function setUp(): void
    {
        $this->forwarded = [];
        // Stands in for the app's own handler: the collector must hand
        // every error on to it.
        \set_error_handler(function (int $errno, string $errstr): bool {
            $this->forwarded[] = $errstr;

            return true;
        });
    }

    protected function tearDown(): void
    {
        \restore_error_handler();
    }

    public function testRecordsDroppedAttributesAndForwardsToThePreviousHandler(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $collector = RosannaNoticeCollector::install($profiler);

        try {
            $html = new Renderer()->renderElement(new Element('li', [
                'key' => '1',
                'style' => ['color' => 'red'],
                'dangerouslySetInnerHTML' => ['__html' => '<b>x</b>'],
            ], ['a']));
        } finally {
            $collector->restore();
        }

        // What the README documents: `key` is rendered, the arrays are dropped.
        self::assertSame('<li key="1">a</li>', $html);

        $events = $profile->getEvents();
        self::assertCount(2, $events);
        self::assertSame('rosanna', $events[0]->collector);
        self::assertSame('notice', $events[0]->label);
        self::assertSame('Non-scalar value for attribute "style" (array) was dropped.', $events[0]->payload['message']);
        self::assertSame('Non-scalar value for attribute "dangerouslySetInnerHTML" (array) was dropped.', $events[1]->payload['message']);

        self::assertCount(2, $this->forwarded);
    }

    public function testIgnoresNoticesFromOutsideRosannaButStillForwardsThem(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $collector = RosannaNoticeCollector::install($profiler);

        try {
            \trigger_error('app notice', \E_USER_NOTICE);
        } finally {
            $collector->restore();
        }

        self::assertSame([], $profile->getEvents());
        self::assertSame(['app notice'], $this->forwarded);
    }

    public function testRestoreReinstatesThePreviousHandlerOnce(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $collector = RosannaNoticeCollector::install($profiler);
        $collector->restore();
        $collector->restore();

        new Renderer()->renderElement(new Element('div', ['style' => ['color' => 'red']]));

        self::assertSame([], $profile->getEvents());
        self::assertCount(1, $this->forwarded);
    }
}
