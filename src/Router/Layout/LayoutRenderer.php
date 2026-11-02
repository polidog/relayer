<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Form\FormActionTransformer;
use Polidog\Rosanna\Runtime\DeferredRenderer;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderer;

final class LayoutRenderer
{
    private string $formActionUrl;

    public function __construct(
        ?string $formActionUrl = null,
        private readonly ?DeferredRenderer $deferred = null,
    ) {
        $this->formActionUrl = $formActionUrl ?? Request::fromGlobals()->uri();
    }

    public function render(Element $pageContent, LayoutStack $layouts): string
    {
        return $this->renderElement($this->compose($pageContent, $layouts));
    }

    /**
     * The page wrapped in its layouts, with form actions normalized.
     */
    public function compose(Element $pageContent, LayoutStack $layouts): Element
    {
        $currentContent = $pageContent;

        foreach ($layouts->reversed() as $layout) {
            $layout->setChildren($currentContent);
            $currentContent = $layout->render();
        }

        $currentContent = FormActionTransformer::apply($currentContent, $this->formActionUrl);
        \assert($currentContent instanceof Element);

        return $currentContent;
    }

    public function renderElement(Element $element): string
    {
        return new Renderer(deferred: $this->deferred)->renderElement($element);
    }
}
