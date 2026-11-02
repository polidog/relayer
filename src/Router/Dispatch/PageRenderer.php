<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Component\RenderableChildren;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Layout\ScriptCollection;
use Polidog\Relayer\Router\Layout\TrackingDeferredRenderer;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/**
 * Renders a page inside its layouts and document, running any form action the
 * request carries, and echoes the result.
 *
 * @internal
 */
final readonly class PageRenderer
{
    public function __construct(
        private DocumentInterface $document,
        private RosannaDefer $rosanna,
        private ?Profiler $profiler,
    ) {}

    public function renderPage(FunctionPage|Renderable $page, LayoutStack $layouts, Request $request): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class . '#page';

        // Surface server actions (form POST hitting `$ctx->action()` or a
        // class-style `actionXyz` handler) as profiler events, detected by
        // sniffing the POST body here (the token shape is the same across
        // page kinds) instead of duplicating the dispatcher logic. No-op when
        // no profiler is bound — guarded at the single call site.
        if (null !== $this->profiler) {
            $this->recordProfilerPostDispatches($page, $componentId, $request);
        }

        $span = $this->profiler?->start('page', 'render');

        try {
            $this->renderPageInternal($page, $layouts, $request);
        } finally {
            $span?->stop(['componentId' => $componentId]);
        }
    }

    /**
     * Unwrapped render path; {@see renderPage()} is the profiler-aware
     * facade. Split so the profiler's `page.render` span is wrapped by
     * a try/finally on the normal-return path.
     */
    private function renderPageInternal(FunctionPage|Renderable $page, LayoutStack $layouts, Request $request): void
    {
        $deferred = new TrackingDeferredRenderer($this->rosanna->placeholderRenderer());
        $renderer = new LayoutRenderer(self::formActionUrl($request), $deferred);
        $tree = $page instanceof FunctionPage && $page->hasPendingAction()
            ? $this->renderAroundAction($page, $layouts, $renderer)
            : $renderer->compose($this->renderPageElement($page), $layouts);
        $this->setDocumentMetadata($page);
        $html = $renderer->renderElement($tree);

        if ($this->document instanceof HtmlDocument) {
            $this->document->setClientScriptNeeded($deferred->hasRendered());
        }

        echo $this->renderPageDocument($page, $layouts, $html);
    }

    /**
     * Where a form posts back to: the requested path and query, always
     * origin-relative so the CSRF token never leaves this host.
     *
     * Built from the verbatim {@see Request::uri()}, not {@see Request::$path},
     * so the locale prefix the browser asked for stays. An absolute-form
     * target (`http://other.example/p?q=1`) loses its scheme and authority. A
     * browser reads `//evil.example` or `/\evil.example` as another host, so
     * the leading slashes collapse to one, and tabs and newlines go first
     * because a browser drops them before it parses (`/\t/evil.example`).
     */
    private static function formActionUrl(Request $request): string
    {
        $uri = \str_replace(["\t", "\n", "\r"], '', $request->uri());
        $relative = (string) \preg_replace('#^[a-z][a-z0-9+.\-]*:(?:[/\\\]{2}[^/\\\?\#]*)?#i', '', $uri);

        return '/' . \ltrim($relative, '/\\');
    }

    /**
     * Run the page's own render, including any form action on this POST. The
     * layouts render later, in {@see LayoutRenderer}.
     */
    private function renderPageElement(FunctionPage|Renderable $page): Element
    {
        if ($page instanceof FunctionPage) {
            $page->dispatchActionFromRequest(); // no-op on GET
        } elseif ($page instanceof PageComponent) {
            $page->dispatchActionFromRequest();
        }

        return $page->render();
    }

    /**
     * The double render of a function page on a POST that submits an action
     * registered while rendering — by the render closure, or by a
     * `Renderable` child of the page or of a layout:
     *
     * 1. Render the page inside its layouts and every `Renderable` child in
     *    the tree, so the action is registered.
     * 2. Dispatch it (it may redirect or abort, or change state captured by
     *    reference in the factory or kept on a child's `$this`).
     * 3. Render the page and its layouts again for the response, carrying
     *    over what the action changed on the children of step 1
     *    ({@see RenderableChildren}).
     */
    private function renderAroundAction(FunctionPage $page, LayoutStack $layouts, LayoutRenderer $renderer): Element
    {
        $scripts = [];
        foreach ($layouts->all() as $index => $layout) {
            if ($layout instanceof LayoutComponent) {
                $scripts[$index] = $layout->getScripts();
            }
        }

        $children = RenderableChildren::render($renderer->compose($page->renderToRegisterActions(), $layouts));
        $page->dispatchActionFromRequest();

        foreach ($layouts->all() as $index => $layout) {
            if ($layout instanceof LayoutComponent) {
                $layout->restoreScripts($scripts[$index] ?? []);
            }
        }

        return $children->resolve($renderer->compose($page->renderAfterDispatch(), $layouts));
    }

    private function setDocumentMetadata(FunctionPage|Renderable $page): void
    {
        if (!$this->document instanceof HtmlDocument) {
            return;
        }

        if ($page instanceof FunctionPage) {
            $this->document->setMetadata($page->getMetadata());
        } elseif ($page instanceof PageComponent) {
            $this->document->setMetadata($page->getMetadata());
        }
    }

    private function renderPageDocument(
        FunctionPage|Renderable $page,
        LayoutStack $layouts,
        string $html,
    ): string {
        // Collected here, not right after $page->render(): a layout's
        // render() only runs inside LayoutRenderer::render(), so a layout
        // declaring scripts via addJs() inside render() would otherwise be
        // missed.
        if ($this->document instanceof HtmlDocument) {
            foreach (ScriptCollection::gather($page, $layouts) as $script) {
                $this->document->addScript($script);
            }
        }

        return $this->document->render($html);
    }

    /**
     * Sniff the `_rosanna_action` form field and emit `action.dispatch` for a
     * form-action token. The caller has already guarded against a null
     * profiler so this method assumes one is bound.
     */
    private function recordProfilerPostDispatches(FunctionPage|Renderable $page, string $componentId, Request $request): void
    {
        \assert(null !== $this->profiler);

        if (!$request->isPost()) {
            return;
        }

        $token = $request->post('_rosanna_action');
        if (null === $token || !\str_starts_with($token, 'rosanna-action:')) {
            return;
        }

        $this->profiler->collect('action', 'dispatch', [
            'kind' => $page instanceof FunctionPage ? 'function' : 'class',
            'page' => $componentId,
        ]);
    }
}
