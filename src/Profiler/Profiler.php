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
     * Time `$fn`, record one span for it, and return whatever `$fn`
     * returns. The ergonomic wrapper around {@see start()} for the common
     * "just measure this call" case — no manual `stop()`, and the span is
     * still recorded (with an `error` payload) if `$fn` throws, before the
     * exception propagates unchanged.
     *
     * This is the supported way to make your own code — a third-party
     * library, an internal service — visible on the profiler timeline
     * without writing a bespoke `Traceable*` decorator: wrap the call site.
     *
     * By design it records **only** the timing under `$collector`/`$label`
     * — never `$fn`'s arguments or return value. The hand-written
     * `Traceable*` decorators each redact domain-specifically (a password
     * being hashed, an `Authorization` header); a generic wrapper cannot
     * know what is sensitive, so it records nothing it was not explicitly
     * given. If you want a (sanitized) payload, use {@see start()} +
     * {@see TraceSpan::stop()} directly and pass only what is safe.
     *
     * In prod ({@see NullProfiler}) `$fn` still runs and its value/throw is
     * passed through; only the recording is skipped.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function measure(string $collector, string $label, callable $fn): mixed;

    /**
     * Returns the current request's {@see Profile} when one is active, or
     * `null` outside the dispatch window (and always `null` for
     * {@see NullProfiler}).
     */
    public function currentProfile(): ?Profile;

    public function isEnabled(): bool;
}
