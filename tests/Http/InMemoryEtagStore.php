<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use Polidog\Relayer\Http\EtagStore;

/**
 * In-memory EtagStore for unit tests. Avoids touching the filesystem.
 */
final class InMemoryEtagStore implements EtagStore
{
    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $etag): void
    {
        $this->values[$key] = $etag;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }
}
