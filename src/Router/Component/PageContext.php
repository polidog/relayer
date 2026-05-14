<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

final class PageContext
{
    /** @var array<string, string> */
    private array $metadata = [];

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
}
