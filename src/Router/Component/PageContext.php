<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

final class PageContext
{
    private array $metadata = [];

    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly array $params = [],
    ) {}

    public function metadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
