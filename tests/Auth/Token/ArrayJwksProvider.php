<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use Firebase\JWT\Key;
use Polidog\Relayer\Auth\Token\JwksProvider;
use Throwable;

/**
 * In-memory {@see JwksProvider} for verifier tests: serves a fixed key
 * set, counts calls so refresh-on-rotation can be asserted, can simulate
 * a rotated set that only appears after {@see refresh()}, and can throw
 * on {@see keys()} to exercise the infra-failure (fail-loud) boundary.
 *
 * Not named `*Test`, so PHPUnit skips it; PSR-4 autoload still loads it.
 */
final class ArrayJwksProvider implements JwksProvider
{
    public int $keysCalls = 0;

    public int $refreshCalls = 0;

    public ?Throwable $throwOnKeys = null;

    /** @var array<string, Key> */
    private array $current;

    /** @var null|array<string, Key> */
    private ?array $afterRefresh = null;

    /**
     * @param array<string, Key> $keys
     */
    public function __construct(array $keys)
    {
        $this->current = $keys;
    }

    /**
     * Make {@see refresh()} swap in a different key set, modelling an IdP
     * key rotation that the cached set has not yet seen.
     *
     * @param array<string, Key> $keys
     */
    public function rotateOnRefresh(array $keys): void
    {
        $this->afterRefresh = $keys;
    }

    public function keys(): array
    {
        ++$this->keysCalls;
        if (null !== $this->throwOnKeys) {
            throw $this->throwOnKeys;
        }

        return $this->current;
    }

    public function refresh(): array
    {
        ++$this->refreshCalls;
        if (null !== $this->afterRefresh) {
            $this->current = $this->afterRefresh;
        }

        return $this->current;
    }
}
