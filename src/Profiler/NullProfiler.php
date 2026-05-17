<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

use Throwable;

/**
 * Production no-op {@see Profiler}. Every method short-circuits without
 * allocating a Profile or touching storage, so user code calling
 * `$profiler->collect(...)` from a hot path pays only the cost of a
 * dispatched virtual method.
 */
final class NullProfiler implements Profiler
{
    /**
     * @param array<string, mixed> $payload
     */
    public function collect(string $collector, string $label, array $payload = []): void {}

    public function start(string $collector, string $label): TraceSpan
    {
        // No-op stop callback — the span still satisfies the TraceSpan
        // contract so callers don't need to null-check the return value.
        return new TraceSpan(static fn (float $durationMs, array $payload): null => null, \microtime(true));
    }

    public function measure(string $collector, string $label, callable $fn): mixed
    {
        // Same shape as RecordingProfiler::measure() — the no-op span makes
        // it free, but $fn still runs and its value/throw passes through so
        // wrapped code behaves identically in prod and dev.
        $span = $this->start($collector, $label);

        try {
            $result = $fn();
            $span->stop();

            return $result;
        } catch (Throwable $e) {
            $span->stop(['error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function currentProfile(): ?Profile
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
