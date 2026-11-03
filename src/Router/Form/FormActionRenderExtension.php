<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use Closure;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderExtension;
use RuntimeException;

/**
 * Turns a `<form action={$token}>` into a real form while it renders: the
 * token moves into a hidden field, the CSRF field is added, and the form posts
 * back to the current URL.
 *
 * Working at render time means every form the Renderer reaches is covered,
 * including those inside a Renderable child or a defer fallback.
 */
final readonly class FormActionRenderExtension implements RenderExtension
{
    private const ACTION_FIELD = '_rosanna_action';
    private const CSRF_FIELD = '_rosanna_csrf';

    public function __construct(private string $actionUrl) {}

    public function beginRender(): void {}

    public function finishRender(): string
    {
        return '';
    }

    public function endRender(): void {}

    public function renderTag(Element $element, string $lowerTag, bool $isVoid, Closure $attributes, Closure $content): string
    {
        // The Renderer leaves this check to the extension once one is set.
        foreach (\array_keys($element->props) as $key) {
            if (0 === \stripos((string) $key, 'wire:')) {
                throw new RuntimeException(\sprintf('%s needs polidog/rosanna-state: event bindings do nothing without the state layer.', $key));
            }
        }

        $props = $element->props;
        $children = $element->children;

        if ('form' === $lowerTag && \is_string($props['action'] ?? null) && FormAction::isToken($props['action'])) {
            [$props, $children] = $this->normalizeForm($props, $children);
        }

        $tag = $element->type;
        $html = $attributes($lowerTag, $props);

        return $isVoid
            ? "<{$tag}{$html} />"
            : "<{$tag}{$html}>{$content($lowerTag, $children)}</{$tag}>";
    }

    /**
     * @param array<string, mixed> $props    with a form-action token as `action`
     * @param array<mixed>         $children
     *
     * @return array{array<string, mixed>, array<mixed>}
     */
    private function normalizeForm(array $props, array $children): array
    {
        $token = $props['action'];
        $props['action'] = $this->actionUrl;
        $props['method'] ??= 'post';
        $props['data-rosanna-form'] ??= '1';

        if (!self::hasField($children, self::ACTION_FIELD)) {
            \array_unshift($children, self::hiddenField(self::ACTION_FIELD, $token));
        }

        if (!self::hasField($children, self::CSRF_FIELD)) {
            \array_unshift($children, self::hiddenField(self::CSRF_FIELD, CsrfToken::getToken()));
        }

        return [$props, $children];
    }

    private static function hiddenField(string $name, mixed $value): Element
    {
        return new Element('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }

    /**
     * @param array<mixed> $children
     */
    private static function hasField(array $children, string $name): bool
    {
        foreach ($children as $child) {
            if (\is_array($child) && self::hasField($child, $name)) {
                return true;
            }

            if ($child instanceof Element && 'input' === $child->type && $name === ($child->props['name'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
