<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Closure;
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

    /**
     * @param null|Closure(string, string): ?string $actionSigner signs each
     *                                                            state action
     *                                                            rendered, so
     *                                                            only those can
     *                                                            be replayed
     */
    public function __construct(
        private readonly string $componentId = 'page',
        ?string $formActionUrl = null,
        private readonly ?SnapshotSerializer $snapshotSerializer = null,
        private readonly ?Closure $actionSigner = null,
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
     * useState form it emits (any `wire:*` event → hidden-field form)
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
            actionSigner: $this->actionSigner,
        );
    }

    private static function containsStateAction(Element $node): bool
    {
        foreach ($node->props as $name => $value) {
            if ($value instanceof Action && \str_starts_with($name, 'wire:')) {
                return true;
            }
        }

        foreach ($node->children as $child) {
            if ($child instanceof Element && self::containsStateAction($child)) {
                return true;
            }
        }

        return false;
    }
}
