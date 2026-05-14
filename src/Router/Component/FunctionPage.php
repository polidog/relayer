<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Relayer\Http\Cache;
use Polidog\UsePhp\Runtime\Element;

final class FunctionPage
{
    public function __construct(
        private Closure $renderFn,
        private PageContext $context,
        private string $pageId,
    ) {}

    public function render(): Element
    {
        $element = ($this->renderFn)();
        \assert($element instanceof Element);

        return $element;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->context->getMetadata();
    }

    public function getComponentId(): string
    {
        return 'page:' . $this->pageId;
    }

    public function getCache(): ?Cache
    {
        return $this->context->getCache();
    }
}
