<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Polidog\UsePhp\Runtime\Element;

final class FunctionPage
{
    public function __construct(
        private \Closure $renderFn,
        private PageContext $context,
        private string $pageId,
    ) {}

    public function render(): Element
    {
        return ($this->renderFn)();
    }

    public function getMetadata(): array
    {
        return $this->context->getMetadata();
    }

    public function getComponentId(): string
    {
        return 'page:' . $this->pageId;
    }
}
