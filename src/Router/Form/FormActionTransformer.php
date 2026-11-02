<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use Polidog\Rosanna\Runtime\Element;

final class FormActionTransformer
{
    /**
     * PSX `{expr}` children reach the tree unchecked, so numbers and booleans
     * can appear in spite of Element's phpdoc. They are normalized here to
     * what Rosanna's Renderer::renderChildren() would output for them: numbers
     * as text (including 0) and booleans as nothing, matching React. Numbers
     * become strings so that tree walkers after this one see fewer shapes.
     */
    public static function apply(bool|Element|float|int|string|null $node, string $defaultActionUrl): Element|string|null
    {
        if (null === $node || \is_string($node)) {
            return $node;
        }

        if (\is_bool($node)) {
            return null;
        }

        if (\is_int($node) || \is_float($node)) {
            return (string) $node;
        }

        $newChildren = self::applyChildren($node->children, $defaultActionUrl);

        if ('form' === $node->type) {
            return self::normalizeForm($node, $newChildren, $defaultActionUrl);
        }

        return new Element($node->type, $node->props, $newChildren);
    }

    /**
     * Element's children may hold nulls and nested arrays (Rosanna's Renderer
     * flattens the latter), so walk them here and hand back a flat list. A
     * Renderable, Stringable or anything else is left for the Renderer, which
     * renders it or throws.
     *
     * @param array<mixed> $children
     *
     * @return array<int, mixed>
     */
    private static function applyChildren(array $children, string $defaultActionUrl): array
    {
        $applied = [];
        foreach ($children as $child) {
            if (\is_array($child)) {
                \array_push($applied, ...self::applyChildren($child, $defaultActionUrl));

                continue;
            }

            if (null === $child || \is_scalar($child) || $child instanceof Element) {
                $child = self::apply($child, $defaultActionUrl);
                if (null === $child) {
                    continue;
                }
            }

            $applied[] = $child;
        }

        return $applied;
    }

    /**
     * @param array<int, mixed> $children
     */
    private static function normalizeForm(Element $form, array $children, string $defaultActionUrl): Element
    {
        $action = $form->props['action'] ?? null;

        if (!\is_string($action) || !FormAction::isToken($action)) {
            return new Element($form->type, $form->props, $children);
        }

        $newProps = $form->props;
        $newProps['action'] = $defaultActionUrl;
        $newProps['method'] = $newProps['method'] ?? 'post';
        $newProps['data-rosanna-form'] = $newProps['data-rosanna-form'] ?? '1';

        $newChildren = $children;

        if (!self::hasHiddenAction($children)) {
            \array_unshift(
                $newChildren,
                new Element('input', [
                    'type' => 'hidden',
                    'name' => '_rosanna_action',
                    'value' => $action,
                ]),
            );
        }

        if (!self::hasHiddenCsrf($newChildren)) {
            \array_unshift(
                $newChildren,
                new Element('input', [
                    'type' => 'hidden',
                    'name' => '_rosanna_csrf',
                    'value' => CsrfToken::getToken(),
                ]),
            );
        }

        return new Element($form->type, $newProps, $newChildren);
    }

    /**
     * @param array<int, mixed> $children
     */
    private static function hasHiddenAction(array $children): bool
    {
        foreach ($children as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            if ('input' !== $child->type) {
                continue;
            }

            $name = $child->props['name'] ?? null;
            if ('_rosanna_action' === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $children
     */
    private static function hasHiddenCsrf(array $children): bool
    {
        foreach ($children as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            if ('input' !== $child->type) {
                continue;
            }

            $name = $child->props['name'] ?? null;
            if ('_rosanna_csrf' === $name) {
                return true;
            }
        }

        return false;
    }
}
