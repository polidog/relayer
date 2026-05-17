<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

use Polidog\Relayer\Router\TraceableAppRouter;
use Throwable;

/**
 * Dev-time {@see Profiler} that builds a {@see Profile} per request and
 * hands it to a {@see ProfilerStorage} on completion.
 *
 * Lifecycle:
 * - {@see beginProfile()} is called by {@see TraceableAppRouter::run()}
 *   at the start of dispatch.
 * - During dispatch, framework code and user code call {@see collect()} /
 *   {@see start()} which mutate the in-flight Profile.
 * - {@see endProfile()} stamps the status code + endedAt and persists.
 *
 * Concurrent requests run in separate PHP processes under PHP-FPM, so a
 * single profile field is safe. For long-running runtimes a fresh
 * RecordingProfiler instance is required per request — set the DI service
 * to non-shared if you adopt such a runtime.
 */
final class RecordingProfiler implements Profiler
{
    private ?Profile $profile = null;

    public function __construct(private readonly ?ProfilerStorage $storage = null) {}

    /**
     * @param ?string $parentToken Token of the request that triggered this
     *                             one (e.g. a `<X defer />` fetch initiated
     *                             by the parent page render). Null for
     *                             top-level browser requests.
     */
    public function beginProfile(string $url, string $method, ?string $parentToken = null): Profile
    {
        $this->profile = new Profile(
            token: \bin2hex(\random_bytes(8)),
            url: $url,
            method: $method,
            startedAt: \microtime(true),
            parentToken: $parentToken,
        );

        return $this->profile;
    }

    /**
     * Idempotent — first call finalizes and persists, subsequent calls
     * are no-ops. Lets a request that ended via `exit/die` get the
     * profile saved by the shutdown handler without overwriting an
     * earlier explicit call (e.g. the 304 short-circuit path).
     */
    public function endProfile(int $statusCode): void
    {
        if (null === $this->profile || null !== $this->profile->getEndedAt()) {
            return;
        }
        $this->profile->end($statusCode);
        $this->storage?->save($this->profile);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function collect(string $collector, string $label, array $payload = []): void
    {
        $this->profile?->addEvent(new Event($collector, $label, $payload, \microtime(true)));
    }

    public function start(string $collector, string $label): TraceSpan
    {
        $startedAt = \microtime(true);
        $profile = $this->profile;

        return new TraceSpan(
            static function (float $durationMs, array $payload) use ($collector, $label, $startedAt, $profile): void {
                $profile?->addEvent(new Event($collector, $label, $payload, $startedAt, $durationMs));
            },
            $startedAt,
        );
    }

    public function measure(string $collector, string $label, callable $fn): mixed
    {
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
        return $this->profile;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
