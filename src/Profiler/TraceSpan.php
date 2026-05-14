<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

use Closure;

/**
 * Handle returned by {@see Profiler::start()} for measuring an operation's
 * duration. The caller invokes {@see stop()} at the end of the operation,
 * which records a single {@see Event} with the elapsed time in milliseconds.
 *
 * `stop()` is idempotent — repeat calls are no-ops — so it can be safely
 * placed in a `finally` block without double-recording on exception paths.
 */
final class TraceSpan
{
    private bool $stopped = false;

    /**
     * @param Closure(float, array<string, mixed>): void $onStop callback invoked with
     *                                                           (durationMs, payload)
     */
    public function __construct(
        private readonly Closure $onStop,
        private readonly float $startedAt,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function stop(array $payload = []): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $durationMs = (\microtime(true) - $this->startedAt) * 1000.0;
        ($this->onStop)($durationMs, $payload);
    }
}
