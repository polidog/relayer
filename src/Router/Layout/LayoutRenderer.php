<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\Relayer\Router\Form\FormActionTransformer;

final class LayoutRenderer
{
    private Renderer $renderer;
    private string $formActionUrl;

    public function __construct(string $componentId = 'page', ?string $formActionUrl = null)
    {
        $this->renderer = new Renderer($componentId);
        $this->formActionUrl = $formActionUrl ?? ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function render(Element $pageContent, LayoutStack $layouts): string
    {
        if ($layouts->isEmpty()) {
            $pageContent = FormActionTransformer::apply($pageContent, $this->formActionUrl);
            return $this->renderer->renderElement($pageContent);
        }

        $currentContent = $pageContent;

        $layoutsArray = $layouts->reversed();

        foreach ($layoutsArray as $layout) {
            $layout->setChildren($currentContent);
            $currentContent = $layout->render();
        }

        $currentContent = FormActionTransformer::apply($currentContent, $this->formActionUrl);

        return $this->renderer->renderElement($currentContent);
    }

    public function renderElement(Element $element): string
    {
        return $this->renderer->renderElement($element);
    }
}
