<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
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
        $html = $renderer->render($this->renderPageElement($page), $layouts);

        if ($this->document instanceof HtmlDocument) {
            $this->document->setClientScriptNeeded($deferred->hasRendered());
        }

        echo $this->renderPageDocument($page, $layouts, $html);
    }

    /**
     * Where a form posts back to: the requested URL, kept on this host. A
     * browser reads an action of `//evil.example` as another host, and such a
     * form would send the CSRF token there.
     */
    private static function formActionUrl(Request $request): string
    {
        return (string) \preg_replace('#^[/\\\]+#', '/', $request->uri());
    }

    /**
     * Run the page's own render, including any form action on this POST. The
     * layouts render later, in {@see LayoutRenderer}.
     */
    private function renderPageElement(FunctionPage|Renderable $page): Element
    {
        if ($page instanceof FunctionPage) {
            if ($page->hasPendingAction()) {
                // Double-render for function pages with a matching POST action:
                // 1. Pre-render: executes the render closure so sub-components can
                //    self-register their server actions via PageContext::current()->action().
                //    The returned element is discarded.
                // 2. Dispatch: invokes the matched action handler (may redirect/abort,
                //    or mutate factory-scoped shared state such as $errors references).
                // 3. Re-render: FunctionPage::renderAfterDispatch() clears the action
                //    registry and re-runs the render closure so (a) sub-component actions
                //    are re-registered for the response form and (b) the element reflects
                //    any state mutations made by the action handler.
                $page->render();
                $page->dispatchActionFromRequest();
                $pageElement = $page->renderAfterDispatch();
            } else {
                $page->dispatchActionFromRequest(); // no-op on GET
                $pageElement = $page->render();
            }
        } else {
            if ($page instanceof PageComponent) {
                $page->dispatchActionFromRequest();
            }
            $pageElement = $page->render();
        }

        if ($page instanceof FunctionPage && $this->document instanceof HtmlDocument) {
            /** @var array<string, string> $metadata */
            $metadata = $page->getMetadata();
            $this->document->setMetadata($metadata);
        } elseif ($page instanceof PageComponent && $this->document instanceof HtmlDocument) {
            $this->document->setMetadata($page->getMetadata());
        }

        return $pageElement;
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
