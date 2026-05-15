<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\Relayer\Router\Form\FormActionTransformer;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;

final class LayoutRenderer
{
    private Renderer $renderer;
    private string $formActionUrl;

    public function __construct(
        string $componentId = 'page',
        ?string $formActionUrl = null,
        ?SnapshotSerializer $snapshotSerializer = null,
    ) {
        // Renderer takes the SnapshotSerializer so snapshot-backed component
        // state can be serialized into the rendered HTML. Passing null still
        // works — Renderer falls back to unsigned snapshot JSON.
        $this->renderer = new Renderer($componentId, $snapshotSerializer);
        if (null === $formActionUrl) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $formActionUrl = \is_string($requestUri) ? $requestUri : '/';
        }
        $this->formActionUrl = $formActionUrl;
    }

    public function render(Element $pageContent, LayoutStack $layouts): string
    {
        if ($layouts->isEmpty()) {
            $pageContent = FormActionTransformer::apply($pageContent, $this->formActionUrl);
            \assert($pageContent instanceof Element);

            return $this->renderer->renderElement($pageContent);
        }

        $currentContent = $pageContent;

        $layoutsArray = $layouts->reversed();

        foreach ($layoutsArray as $layout) {
            $layout->setChildren($currentContent);
            $currentContent = $layout->render();
        }

        $currentContent = FormActionTransformer::apply($currentContent, $this->formActionUrl);
        \assert($currentContent instanceof Element);

        return $this->renderer->renderElement($currentContent);
    }

    public function renderElement(Element $element): string
    {
        return $this->renderer->renderElement($element);
    }
}
