<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Profiler\Profiler;

/**
 * Dev-only {@see SessionStorage} decorator that records operations into
 * the request-scoped {@see Profiler}. Helpful for spotting session
 * bloat (many writes per request) or surprising regenerateId calls.
 *
 * Only keys are recorded, never values — the contents of the session
 * are sensitive (auth identity, CSRF tokens) and would otherwise land
 * in plain-text JSON dumps under `var/cache/profiler/`.
 */
final class TraceableSessionStorage implements SessionStorage
{
    public function __construct(
        private readonly SessionStorage $inner,
        private readonly Profiler $profiler,
    ) {}

    public function get(string $key): mixed
    {
        $value = $this->inner->get($key);
        $this->profiler->collect('session', 'get', [
            'key' => $key,
            'hit' => null !== $value,
        ]);

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
        $this->profiler->collect('session', 'set', [
            'key' => $key,
        ]);
    }

    public function remove(string $key): void
    {
        $this->inner->remove($key);
        $this->profiler->collect('session', 'remove', [
            'key' => $key,
        ]);
    }

    public function regenerateId(): void
    {
        $this->inner->regenerateId();
        $this->profiler->collect('session', 'regenerate_id', []);
    }

    public function clear(): void
    {
        $this->inner->clear();
        $this->profiler->collect('session', 'clear', []);
    }
}
