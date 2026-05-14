<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\UsePhp\Runtime\Element;

final class FunctionPage
{
    private const FORM_ACTION_FIELD = '_usephp_action';
    private const FORM_CSRF_FIELD = '_usephp_csrf';

    public function __construct(
        private Closure $renderFn,
        private PageContext $context,
        private string $pageId,
    ) {}

    /**
     * Resolve a POST request to a registered server action on this page and
     * invoke it. Mirrors PageComponent::dispatchActionFromRequest() but
     * dispatches by (pageId, name) instead of (class, method).
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

        $name = $payload['name'] ?? null;
        if (!\is_string($name)) {
            return;
        }

        $handler = $this->context->getAction($name);
        if (null === $handler) {
            return;
        }

        $formData = $_POST;
        unset($formData[self::FORM_ACTION_FIELD], $formData[self::FORM_CSRF_FIELD]);

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
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->context->getMetadata();
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
