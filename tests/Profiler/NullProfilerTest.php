<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\NullProfiler;
use RuntimeException;

final class NullProfilerTest extends TestCase
{
    public function testCollectIsNoop(): void
    {
        $profiler = new NullProfiler();

        $profiler->collect('db', 'query', ['sql' => 'SELECT 1']);

        // No state to observe — the contract is purely "doesn't blow up".
        self::assertNull($profiler->currentProfile());
        self::assertFalse($profiler->isEnabled());
    }

    public function testStartReturnsUsableSpan(): void
    {
        $profiler = new NullProfiler();

        $span = $profiler->start('db', 'query');
        $span->stop(['rows' => 3]);

        // Repeated stop() must remain a no-op too — callers commonly put it
        // in a finally that may re-run on edge paths.
        $span->stop();
        self::assertNull($profiler->currentProfile());
    }

    public function testMeasureRunsCallbackAndReturnsValueWithoutRecording(): void
    {
        $profiler = new NullProfiler();
        $ran = false;

        $result = $profiler->measure('lib', 'thing', static function () use (&$ran): int {
            $ran = true;

            return 42;
        });

        self::assertTrue($ran, 'callback still runs in prod');
        self::assertSame(42, $result, 'callback value passes through');
        self::assertNull($profiler->currentProfile());
    }

    public function testMeasureRethrowsCallbackException(): void
    {
        $profiler = new NullProfiler();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $profiler->measure('lib', 'thing', static function (): never {
            throw new RuntimeException('boom');
        });
    }
}
