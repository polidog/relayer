<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\NullProfiler;

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
}
