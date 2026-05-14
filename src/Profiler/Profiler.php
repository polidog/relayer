<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

/**
 * Facade for the framework's request-scoped tracing system.
 *
 * Bound in DI as `Profiler::class`. In `prod` the implementation is
 * {@see NullProfiler} (every call is a no-op); in `dev` it is
 * {@see RecordingProfiler} (events land in the current {@see Profile} and
 * are persisted via {@see ProfilerStorage} at end of request).
 *
 * User code can take a `Profiler` dependency unconditionally — the contract
 * is to never throw and never affect application behavior, so prod stays
 * cost-free without `if profiler enabled` branches in callers.
 */
interface Profiler
{
    /**
     * Record a one-shot event. Returns immediately; no duration is associated
     * (use {@see start()} for timed spans).
     *
     * @param array<string, mixed> $payload
     */
    public function collect(string $collector, string $label, array $payload = []): void;

    /**
     * Begin a timed span. Caller must call {@see TraceSpan::stop()} to
     * record the event with its measured duration; otherwise nothing is
     * recorded.
     */
    public function start(string $collector, string $label): TraceSpan;

    /**
     * Returns the current request's {@see Profile} when one is active, or
     * `null` outside the dispatch window (and always `null` for
     * {@see NullProfiler}).
     */
    public function currentProfile(): ?Profile;

    public function isEnabled(): bool;
}
