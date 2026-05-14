<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Runtime\Element;

abstract class LayoutComponent extends BaseComponent implements LayoutInterface
{
    /** @var Element|array<Element|string>|string */
    private Element|array|string $children = [];

    /** @var array<string, string> */
    private array $params = [];

    /**
     * @param Element|array<Element|string>|string $children
     */
    public function setChildren(Element|array|string $children): void
    {
        $this->children = $children;
    }

    /**
     * @return Element|array<Element|string>|string
     */
    protected function getChildren(): Element|array|string
    {
        return $this->children;
    }

    /**
     * @param array<string, string> $params
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
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
