<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormActionTransformer;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;

final class LayoutRenderer
{
    private string $formActionUrl;

    public function __construct(
        private readonly string $componentId = 'page',
        ?string $formActionUrl = null,
        private readonly ?SnapshotSerializer $snapshotSerializer = null,
    ) {
        $this->formActionUrl = $formActionUrl ?? Request::fromGlobals()->uri();
    }

    public function render(Element $pageContent, LayoutStack $layouts): string
    {
        if ($layouts->isEmpty()) {
            $pageContent = FormActionTransformer::apply($pageContent, $this->formActionUrl);
            \assert($pageContent instanceof Element);

            return $this->renderElement($pageContent);
        }

        $currentContent = $pageContent;

        $layoutsArray = $layouts->reversed();

        foreach ($layoutsArray as $layout) {
            $layout->setChildren($currentContent);
            $currentContent = $layout->render();
        }

        $currentContent = FormActionTransformer::apply($currentContent, $this->formActionUrl);
        \assert($currentContent instanceof Element);

        return $this->renderElement($currentContent);
    }

    public function renderElement(Element $element): string
    {
        return $this->createRenderer($element)->renderElement($element);
    }

    /**
     * Renderer takes the SnapshotSerializer to HMAC-sign snapshot-backed
     * component state rendered into the page. Defer placeholders use the
     * `/_defer/{name}` GET endpoint and do NOT need it. Null is fine for
     * the common case of pages with no `StorageType::Snapshot` component;
     * if a snapshot is actually serialized without one, use-php 0.5.0
     * throws a clear LogicException rather than emitting forgeable
     * unsigned state.
     *
     * The session-bound CSRF token is handed to the Renderer so every
     * useState `setState` form it emits (`wire:click` → hidden-field form)
     * carries `_usephp_csrf`, which AppRouter verifies before writing any
     * state. usePHP's client script submits the form's own FormData on the
     * partial (`X-UsePHP-Partial`) path, so the same hidden field covers
     * both the no-JS PRG POST and the fetch-based partial update. The token
     * is only fetched when the tree actually contains a state action, so
     * plain pages never start a session (which would defeat HTTP caching).
     */
    private function createRenderer(Element $element): Renderer
    {
        $csrfToken = self::containsStateAction($element) ? CsrfToken::getToken() : '';

        return new Renderer(
            $this->componentId,
            $this->snapshotSerializer,
            csrfToken: $csrfToken,
        );
    }

    private static function containsStateAction(Element|string $node): bool
    {
        if (\is_string($node)) {
            return false;
        }

        if (($node->props['wire:click'] ?? null) instanceof Action) {
            return true;
        }

        foreach ($node->children as $child) {
            // PSX conditional children can leave nulls in the tree despite
            // Element's phpdoc; see FormActionTransformer::apply().
            // @phpstan-ignore identical.alwaysFalse
            if (null === $child) {
                continue;
            }
            if (self::containsStateAction($child)) {
                return true;
            }
        }

        return false;
    }
}
