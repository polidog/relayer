<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Countable;

final class LayoutStack implements Countable
{
    /** @var array<LayoutInterface> */
    private array $layouts = [];

    public function push(LayoutInterface $layout): void
    {
        $this->layouts[] = $layout;
    }

    /**
     * @return array<LayoutInterface>
     */
    public function all(): array
    {
        return $this->layouts;
    }

    public function count(): int
    {
        return \count($this->layouts);
    }

    public function isEmpty(): bool
    {
        return 0 === \count($this->layouts);
    }

    /**
     * @return array<LayoutInterface>
     */
    public function reversed(): array
    {
        return \array_reverse($this->layouts);
    }
}
