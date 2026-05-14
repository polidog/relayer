<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

/**
 * A single recorded event in a {@see Profile}.
 *
 * Events are grouped by `collector` (the subsystem that emitted them — e.g.
 * `route`, `cache`, `auth`, `db`) and disambiguated within a collector by
 * `label`. The `payload` is collector-defined free-form data.
 *
 * `durationMs` is populated only for events produced by
 * {@see Profiler::start()} → {@see TraceSpan::stop()}; one-shot events
 * (i.e. those emitted via {@see Profiler::collect()}) leave it null.
 */
final readonly class Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $collector,
        public string $label,
        public array $payload,
        public float $ts,
        public ?float $durationMs = null,
    ) {}

    /**
     * @return array{collector: string, label: string, payload: array<string, mixed>, ts: float, durationMs: ?float}
     */
    public function toArray(): array
    {
        return [
            'collector' => $this->collector,
            'label' => $this->label,
            'payload' => $this->payload,
            'ts' => $this->ts,
            'durationMs' => $this->durationMs,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $payload */
        $payload = \is_array($data['payload'] ?? null) ? $data['payload'] : [];

        $collector = $data['collector'] ?? '';
        $label = $data['label'] ?? '';
        $ts = $data['ts'] ?? 0.0;
        $duration = $data['durationMs'] ?? null;

        return new self(
            collector: \is_string($collector) ? $collector : '',
            label: \is_string($label) ? $label : '',
            payload: $payload,
            ts: \is_numeric($ts) ? (float) $ts : 0.0,
            durationMs: \is_numeric($duration) ? (float) $duration : null,
        );
    }
}
