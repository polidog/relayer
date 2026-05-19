<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use JsonException;
use LogicException;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\UsePHP;

/**
 * Render a class-style or function-style page through its layout stack,
 * emit the HTML response, and handle PRG redirects produced by useState
 * actions arriving via POST.
 *
 * The state-action path runs BEFORE rendering so a setState arriving in the
 * POST body is applied before the component reads it. Form-action tokens
 * (the `usephp-action:` prefix) are ignored here — those are dispatched by
 * `PageComponent::dispatchActionFromRequest()` / `FunctionPage::dispatchActionFromRequest()`,
 * which the render path invokes itself.
 */
final class PageRenderer
{
    public function __construct(
        private readonly DocumentInterface $document,
        private ?UsePHP $usephp = null,
    ) {}

    public function setUsePhp(?UsePHP $usephp): void
    {
        $this->usephp = $usephp;
    }

    /**
     * @param array<string, string> $params
     */
    public function render(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        $state = ComponentState::getInstance($componentId);
        ComponentState::reset();

        // Handle useState action (onClick etc.) before rendering.
        $this->dispatchStateAction($componentId, $state);

        if ($page instanceof BaseComponent) {
            $page->setComponentState($state);
        }

        if ($page instanceof PageComponent) {
            $page->dispatchActionFromRequest();
        } elseif ($page instanceof FunctionPage) {
            $page->dispatchActionFromRequest();
        }

        $pageElement = $page->render();

        if ($page instanceof FunctionPage && $this->document instanceof HtmlDocument) {
            /** @var array<string, string> $metadata */
            $metadata = $page->getMetadata();
            $this->document->setMetadata($metadata);
        } elseif ($page instanceof PageComponent && $this->document instanceof HtmlDocument) {
            $this->document->setMetadata($page->getMetadata());
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        // Pass the configured SnapshotSerializer so the inner Renderer can
        // HMAC-sign snapshot-backed component state rendered into the page.
        // Defer placeholders (`/_defer/{name}` GET endpoint) do NOT use the
        // serializer — only `StorageType::Snapshot` state does.
        //
        // use-php 0.5.0 made getSnapshotSerializer() throw a LogicException
        // when no secret has been configured, instead of silently returning
        // an unsigned serializer. Relayer only configures a secret when
        // USEPHP_SNAPSHOT_SECRET is set (or in dev, via a per-project
        // fallback), so prod-without-secret legitimately has none. Degrade
        // to null here: pages with no Snapshot-storage component render
        // exactly as before; a page that actually serializes a snapshot
        // without a secret then fails loudly inside the Renderer with
        // use-php's own actionable message — which is the correct posture,
        // an unsigned client round-trip is forgeable.
        $snapshotSerializer = null;
        if (null !== $this->usephp) {
            try {
                $snapshotSerializer = $this->usephp->getSnapshotSerializer();
            } catch (LogicException) {
                $snapshotSerializer = null;
            }
        }
        $renderer = new LayoutRenderer(
            $componentId,
            \is_string($requestUri) ? $requestUri : '/',
            $snapshotSerializer,
        );
        $html = $renderer->render($pageElement, $layouts);

        if (isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            echo $html;

            return;
        }

        // Collected here, not right after $page->render(): a layout's
        // render() only runs inside $renderer->render() above, so a layout
        // declaring scripts via addJs() inside render() would otherwise be
        // missed. Past the partial early-return too — partial responses
        // bypass the document, so they must not mutate its script queue.
        if ($this->document instanceof HtmlDocument) {
            foreach ($this->collectScripts($page, $layouts) as $script) {
                $this->document->addScript($script);
            }
        }

        $wrappedHtml = \sprintf(
            '<div data-usephp="%s">%s</div>',
            \htmlspecialchars($componentId, \ENT_QUOTES, 'UTF-8'),
            $html,
        );

        $output = $this->document->render($wrappedHtml);

        echo $output;
    }

    /**
     * Gather declared scripts in emission order: outer (root) layout first,
     * inner layouts next, page last. Only LayoutComponent / PageComponent /
     * FunctionPage carry scripts — the same instanceof asymmetry setParams()
     * and metadata already have for raw LayoutInterface implementers. No
     * deduplication: a layout and a page both declaring the same src is two
     * tags by design.
     *
     * @return array<int, Script>
     */
    public function collectScripts(ComponentInterface|FunctionPage $page, LayoutStack $layouts): array
    {
        $scripts = [];

        foreach ($layouts->all() as $layout) {
            if ($layout instanceof LayoutComponent) {
                foreach ($layout->getScripts() as $script) {
                    $scripts[] = $script;
                }
            }
        }

        if ($page instanceof FunctionPage || $page instanceof PageComponent) {
            foreach ($page->getScripts() as $script) {
                $scripts[] = $script;
            }
        }

        return $scripts;
    }

    /**
     * Handle useState setState actions from POST (onClick, onChange, etc.).
     */
    public function dispatchStateAction(string $componentId, ComponentState $state): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $actionJson = $_POST['_usephp_action'] ?? null;
        $postComponentId = $_POST['_usephp_component'] ?? null;

        if (!\is_string($actionJson) || !\is_string($postComponentId)) {
            return;
        }

        // Only handle JSON actions (not usephp-action: form tokens).
        if (\str_starts_with($actionJson, 'usephp-action:')) {
            return;
        }

        if ($postComponentId !== $componentId) {
            return;
        }

        try {
            $actionData = \json_decode($actionJson, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($actionData)) {
                return;
            }

            /** @var array{type: string, payload?: array<string, mixed>, componentId?: null|string, storageType?: null|string} $actionData */
            $action = Action::fromArray($actionData);

            if ('setState' === $action->type) {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                if (!\is_int($index)) {
                    return;
                }
                $state->setState($index, $value);
            }
        } catch (JsonException) {
            return;
        }

        // PRG pattern: redirect after state change (non-AJAX).
        if (!isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $redirectUrl = \strtok(\is_string($requestUri) ? $requestUri : '/', '?');
            \header('Location: ' . $redirectUrl, true, 303);

            exit;
        }
    }
}
