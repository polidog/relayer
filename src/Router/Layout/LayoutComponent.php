<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\Relayer\Router\Document\Script;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Runtime\Element;

abstract class LayoutComponent extends BaseComponent implements LayoutInterface
{
    /** @var array<Element|string>|Element|string */
    private array|Element|string $children = [];

    /** @var array<string, string> */
    private array $params = [];

    /** @var array<int, Script> */
    private array $scripts = [];

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
     * @return array<int, Script>
     *
     * @internal collected by the router into the document after render
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /**
     * @return array<Element|string>|Element|string
     */
    protected function getChildren(): array|Element|string
    {
        return $this->children;
    }

    /**
     * Declare an external script from this layout. Merged with the page's
     * own scripts by the router (this layout's scripts come first, outer
     * layout before inner, page last) and emitted at the end of `<body>`.
     * src-only by design — for inline JS use the document's `addHeadHtml()`.
     */
    protected function addJs(
        string $src,
        bool $defer = false,
        bool $async = false,
        bool $module = false,
    ): void {
        $this->scripts[] = new Script($src, defer: $defer, async: $async, module: $module);
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
