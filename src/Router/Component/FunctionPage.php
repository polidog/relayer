<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Form\ActionInterface;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\UsePhp\Runtime\Element;
use Psr\Container\ContainerInterface;

final class FunctionPage
{
    private const FORM_ACTION_FIELD = '_usephp_action';
    private const FORM_CSRF_FIELD = '_usephp_csrf';

    public function __construct(
        private Closure $renderFn,
        private PageContext $context,
        private string $pageId,
        private ?ContainerInterface $container = null,
    ) {}

    /**
     * Return true when the current request is a POST that carries a form-action
     * token targeting this page. Used by AppRouter to decide whether to run
     * render before dispatch so sub-components can register their actions first.
     *
     * DI-dispatched class actions (di_class token) never need a pre-render
     * pass — the handler is resolved from the container at dispatch time.
     */
    public function hasPendingAction(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }

        $token = $_POST[self::FORM_ACTION_FIELD] ?? null;
        if (!\is_string($token)) {
            return false;
        }

        $payload = FormAction::decode($token);

        if (null === $payload || ($payload['page'] ?? null) !== $this->pageId) {
            return false;
        }

        // DI-dispatched class actions resolve via container — no pre-render.
        if (isset($payload['di_class'])) {
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
        $csrf = $_POST[self::FORM_CSRF_FIELD] ?? null;

        return \is_string($csrf) && CsrfToken::validate($csrf);
    }

    /**
     * Resolve a POST request to a registered server action on this page and
     * invoke it. Mirrors PageComponent::dispatchActionFromRequest() but
     * dispatches by (pageId, name) instead of (class, method).
     *
     * Two dispatch paths:
     *   - di_class token: resolve ActionInterface from the DI container.
     *   - name token: look up the closure registered in PageContext.
     */
    public function dispatchActionFromRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $token = $_POST[self::FORM_ACTION_FIELD] ?? null;

        if (!\is_string($token)) {
            return;
        }

        $payload = FormAction::decode($token);

        if (null === $payload || !isset($payload['page'])) {
            return;
        }

        if ($payload['page'] !== $this->pageId) {
            return;
        }

        $csrf = $_POST[self::FORM_CSRF_FIELD] ?? null;
        if (!\is_string($csrf) || !CsrfToken::validate($csrf)) {
            \http_response_code(403);

            return;
        }

        /** @var array<string, mixed> $formData */
        $formData = $_POST;
        unset($formData[self::FORM_ACTION_FIELD], $formData[self::FORM_CSRF_FIELD]);

        // DI dispatch: resolve the handler class from the container.
        if (isset($payload['di_class'])) {
            $class = $payload['di_class'];
            if (!\is_string($class) || null === $this->container || !$this->container->has($class)) {
                return;
            }
            $handler = $this->container->get($class);
            if ($handler instanceof ActionInterface) {
                $handler->handle($formData);
            }

            return;
        }

        // Closure dispatch: look up the handler registered in PageContext.
        $name = $payload['name'] ?? null;
        if (!\is_string($name)) {
            return;
        }

        $handler = $this->context->getAction($name);
        if (null === $handler) {
            return;
        }

        $args = $payload['args'] ?? [];
        if (!\is_array($args)) {
            $args = [];
        }

        if (\array_is_list($args)) {
            $callArgs = \array_merge([$formData], $args);
        } else {
            $callArgs = \array_merge(['formData' => $formData], $args);
        }

        $handler(...$callArgs);
    }

    public function render(): Element
    {
        $element = ($this->renderFn)();
        \assert($element instanceof Element);

        return $element;
    }

    /**
     * Re-render after a dispatched action that did not redirect. Resets all
     * render-accumulated PageContext state (actions and scripts) so
     * sub-components can re-register without hitting the duplicate-name guard
     * and script tags are not emitted twice. Returns a fresh Element that
     * reflects any state mutated by the action handler.
     *
     * @internal called by AppRouter::renderPageInternal() in the double-render
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
        return 'page:' . $this->pageId;
    }

    public function getCache(): ?Cache
    {
        return $this->context->getCache();
    }
}
