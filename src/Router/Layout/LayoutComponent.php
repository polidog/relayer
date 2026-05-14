<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Runtime\Element;

abstract class LayoutComponent extends BaseComponent implements LayoutInterface
{
    /** @var array<Element|string>|Element|string */
    private array|Element|string $children = [];

    /** @var array<string, string> */
    private array $params = [];

    /**
     * @param array<Element|string>|Element|string $children
     */
    public function setChildren(array|Element|string $children): void
    {
        $this->children = $children;
    }

    /**
     * @param array<string, string> $params
     *
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * @return array<Element|string>|Element|string
     */
    protected function getChildren(): array|Element|string
    {
        return $this->children;
    }

    protected function getParam(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function getParams(): array
    {
        return $this->params;
    }
}
