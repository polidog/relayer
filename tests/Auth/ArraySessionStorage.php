<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use Polidog\Relayer\Auth\SessionStorage;

/**
 * In-memory {@see SessionStorage} for unit tests. Lets us exercise the
 * authenticator without touching PHP's global session machinery, which
 * is awkward in PHPUnit (no headers, "headers already sent" warnings).
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    public array $data = [];

    public int $regenerateCount = 0;

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerateId(): void
    {
        ++$this->regenerateCount;
    }

    public function clear(): void
    {
        $this->data = [];
    }
}
