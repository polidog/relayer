<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Rosanna\Runtime\Element;

final class FunctionPage
{
    private const FORM_ACTION_FIELD = '_rosanna_action';
    private const FORM_CSRF_FIELD = '_rosanna_csrf';

    public function __construct(
        private Closure $renderFn,
        private PageContext $context,
        private string $pageId,
        private ?Request $request = null,
    ) {}

    /**
     * The request snapshot the router handed this page. Falls back to reading
     * the superglobals only when the page was built outside a dispatch.
     */
    public function request(): Request
    {
        return $this->request ??= Request::fromGlobals();
    }

    /**
     * Return true when the current request is a POST that carries a form-action
     * token targeting this page. Used by AppRouter to decide whether to run
     * render before dispatch so sub-components can register their actions first.
     */
    public function hasPendingAction(): bool
    {
        $request = $this->request();
        if (!$request->isPost()) {
            return false;
        }

        $token = $request->post(self::FORM_ACTION_FIELD);
        if (null === $token) {
            return false;
        }

        // Unverified peek: this only decides whether a pre-render pass is
        // worth doing and must not start the session for foreign tokens.
        $payload = FormAction::peek($token);

        if (null === $payload || ($payload['page'] ?? null) !== $this->pageId) {
            return false;
        }

        $name = $payload['name'] ?? null;
        if (!\is_string($name)) {
            return false;
        }

        // Factory-registered actions are always available before dispatch and
        // do not need a render pass; avoid starting the session in that case.
        if (null !== $this->context->getAction($name)) {
            return false;
        }

        // Validate CSRF only when a pre-render pass is actually needed, so
        // malformed/forged POSTs do not trigger the expensive double-render.
        // A forged token (bad signature) must not trigger it either.
        $csrf = $request->post(self::FORM_CSRF_FIELD);

        return null !== $csrf
            && CsrfToken::validate($csrf)
            && null !== FormAction::decode($token);
    }

    /**
     * Resolve a POST request to a registered server action on this page and
     * invoke it. Mirrors PageComponent::dispatchActionFromRequest() but
     * dispatches by (pageId, name) instead of (class, method).
     */
    public function dispatchActionFromRequest(): void
    {
        $request = $this->request();
        if (!$request->isPost()) {
            return;
        }

        $token = $request->post(self::FORM_ACTION_FIELD);

        if (null === $token) {
            return;
        }

        // Cheap unverified routing check first, so a token aimed at another
        // page (or a class-style token) is ignored without touching the
        // session. Nothing from this peek is trusted beyond that.
        $peeked = FormAction::peek($token);

        if (null === $peeked || !isset($peeked['page']) || $peeked['page'] !== $this->pageId) {
            return;
        }

        $csrf = $request->post(self::FORM_CSRF_FIELD);
        if (null === $csrf || !CsrfToken::validate($csrf)) {
            \http_response_code(403);

            return;
        }

        // Verified decode: the name and the bound args below come only from
        // a token this server signed, so a client cannot rewrite them.
        $payload = FormAction::decode($token);
        if (null === $payload || ($payload['page'] ?? null) !== $this->pageId) {
            \http_response_code(403);

            return;
        }

        $name = $payload['name'] ?? null;
        if (!\is_string($name)) {
            return;
        }

        $handler = $this->context->getAction($name);
        if (null === $handler) {
            return;
        }

        $formData = $request->allPost();
        unset($formData[self::FORM_ACTION_FIELD], $formData[self::FORM_CSRF_FIELD]);

        $handler(...FormAction::callArguments($formData, $payload));
    }

    public function render(): Element
    {
        $element = ($this->renderFn)();
        \assert($element instanceof Element);

        return $element;
    }

    /**
     * The render before dispatch, run only so actions registered while
     * rendering exist when the request is dispatched. Besides the page's own
     * render closure, it renders the `Renderable` children the Renderer would
     * otherwise reach only in the final render, after dispatch: a child
     * calling `PageContext::current()->action()` from its `render()` must
     * have registered by then. The element tree itself is discarded.
     *
     * @internal called by PageRenderer::renderPageElement() in the double-render
     *           path (pre-render → dispatch → renderAfterDispatch)
     */
    public function renderToRegisterActions(): void
    {
        self::renderChildren([$this->render()]);
    }

    /**
     * Re-render after a dispatched action that did not redirect. Resets all
     * render-accumulated PageContext state (actions and scripts) so
     * sub-components can re-register without hitting the duplicate-name guard
     * and script tags are not emitted twice. Returns a fresh Element that
     * reflects any state mutated by the action handler.
     *
     * @internal called by PageRenderer::renderPageElement() in the double-render
     *           path (pre-render → dispatch → renderAfterDispatch)
     */
    public function renderAfterDispatch(): Element
    {
        $this->context->clearRenderState();

        return $this->render();
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->context->getMetadata();
    }

    /**
     * @return array<int, Script>
     */
    public function getScripts(): array
    {
        return $this->context->getScripts();
    }

    public function getComponentId(): string
    {
        return 'page:' . $this->pageId . '#page';
    }

    public function getCache(): ?Cache
    {
        return $this->context->getCache();
    }

    /**
     * Walk the tree the way the Renderer does: `resolveRenderables()` expands
     * arrays and calls `resolve()` or `render()` on each child, and of a
     * `__defer__` placeholder only the fallback is rendered on the page.
     *
     * @param array<mixed> $children
     */
    private static function renderChildren(array $children): void
    {
        foreach (\Polidog\Rosanna\Html\resolveRenderables($children) as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            self::renderChildren($child->children);

            $fallback = '__defer__' === $child->type ? $child->props['__fallback'] ?? null : null;
            if ($fallback instanceof Element) {
                self::renderChildren([$fallback]);
            }
        }
    }
}
