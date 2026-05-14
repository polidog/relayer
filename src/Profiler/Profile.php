<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

/**
 * Per-request recording produced by {@see RecordingProfiler}.
 *
 * Identified by an opaque `token` (random 16 hex chars) so a profile can be
 * looked up later by storage. Holds the URL/method/status of the request
 * plus a chronological list of {@see Event}s emitted during dispatch.
 *
 * Mutable on purpose: events are appended as the request progresses, and
 * `end()` finalizes the status code + endedAt at the end of the lifecycle.
 */
final class Profile
{
    /** @var list<Event> */
    private array $events = [];

    private ?float $endedAt = null;

    private ?int $statusCode = null;

    public function __construct(
        public readonly string $token,
        public readonly string $url,
        public readonly string $method,
        public readonly float $startedAt,
    ) {}

    public function addEvent(Event $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<Event> */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Stamp the profile as complete. Called by {@see RecordingProfiler::endProfile()}
     * at the end of the request — after this point only the storage layer
     * is expected to touch the profile.
     */
    public function end(int $statusCode): void
    {
        $this->statusCode = $statusCode;
        $this->endedAt = \microtime(true);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getEndedAt(): ?float
    {
        return $this->endedAt;
    }

    public function durationMs(): ?float
    {
        if (null === $this->endedAt) {
            return null;
        }

        return ($this->endedAt - $this->startedAt) * 1000.0;
    }

    /**
     * @return array{token: string, url: string, method: string, startedAt: float, endedAt: ?float, statusCode: ?int, events: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'url' => $this->url,
            'method' => $this->method,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'statusCode' => $this->statusCode,
            'events' => \array_map(static fn (Event $e): array => $e->toArray(), $this->events),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $token = $data['token'] ?? '';
        $url = $data['url'] ?? '';
        $method = $data['method'] ?? '';
        $startedAt = $data['startedAt'] ?? 0.0;

        $profile = new self(
            token: \is_string($token) ? $token : '',
            url: \is_string($url) ? $url : '',
            method: \is_string($method) ? $method : '',
            startedAt: \is_numeric($startedAt) ? (float) $startedAt : 0.0,
        );

        if (isset($data['statusCode']) && \is_int($data['statusCode'])) {
            $profile->statusCode = $data['statusCode'];
        }
        if (isset($data['endedAt']) && \is_numeric($data['endedAt'])) {
            $profile->endedAt = (float) $data['endedAt'];
        }

        $events = $data['events'] ?? [];
        if (\is_array($events)) {
            foreach ($events as $rawEvent) {
                if (\is_array($rawEvent)) {
                    /** @var array<string, mixed> $rawEvent */
                    $profile->events[] = Event::fromArray($rawEvent);
                }
            }
        }

        return $profile;
    }
}
