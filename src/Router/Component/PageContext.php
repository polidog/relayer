<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Polidog\Relayer\Http\Cache;

final class PageContext
{
    /** @var array<string, string> */
    private array $metadata = [];

    private ?Cache $cache = null;

    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly array $params = [],
    ) {}

    /**
     * @param array<string, string> $metadata
     */
    public function metadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Declare an HTTP cache policy for this page from inside a function-style
     * factory. Used by the framework to emit `Cache-Control` / `ETag` etc. and
     * short-circuit with `304 Not Modified` when the request's conditional
     * headers indicate the client already has a fresh copy.
     *
     * Class-style pages should use the `#[Cache]` attribute instead.
     */
    public function cache(Cache $cache): void
    {
        $this->cache = $cache;
    }

    public function getCache(): ?Cache
    {
        return $this->cache;
    }
}
