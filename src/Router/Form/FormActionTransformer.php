<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use Polidog\UsePhp\Runtime\Element;

final class FormActionTransformer
{
    public static function apply(Element|string $node, string $defaultActionUrl): Element|string
    {
        if (is_string($node)) {
            return $node;
        }

        $newChildren = [];
        foreach ($node->children as $child) {
            if ($child instanceof Element || is_string($child)) {
                $newChildren[] = self::apply($child, $defaultActionUrl);
            } else {
                $newChildren[] = $child;
            }
        }

        if ($node->type === 'form') {
            return self::normalizeForm($node, $newChildren, $defaultActionUrl);
        }

        return new Element($node->type, $node->props, $newChildren);
    }

    /**
     * @param array<int, Element|string|mixed> $children
     */
    private static function normalizeForm(Element $form, array $children, string $defaultActionUrl): Element
    {
        $action = $form->props['action'] ?? null;

        if (!is_string($action) || !FormAction::isToken($action)) {
            return new Element($form->type, $form->props, $children);
        }

        $newProps = $form->props;
        $newProps['action'] = $defaultActionUrl;
        $newProps['method'] = $newProps['method'] ?? 'post';
        $newProps['data-usephp-form'] = $newProps['data-usephp-form'] ?? '1';

        $newChildren = $children;

        if (!self::hasHiddenAction($children)) {
            array_unshift(
                $newChildren,
                new Element('input', [
                    'type' => 'hidden',
                    'name' => '_usephp_action',
                    'value' => $action,
                ])
            );
        }

        if (!self::hasHiddenCsrf($newChildren)) {
            array_unshift(
                $newChildren,
                new Element('input', [
                    'type' => 'hidden',
                    'name' => '_usephp_csrf',
                    'value' => CsrfToken::getToken(),
                ])
            );
        }

        return new Element($form->type, $newProps, $newChildren);
    }

    /**
     * @param array<int, Element|string> $children
     */
    private static function hasHiddenAction(array $children): bool
    {
        foreach ($children as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            if ($child->type !== 'input') {
                continue;
            }

            $name = $child->props['name'] ?? null;
            if ($name === '_usephp_action') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, Element|string> $children
     */
    private static function hasHiddenCsrf(array $children): bool
    {
        foreach ($children as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            if ($child->type !== 'input') {
                continue;
            }

            $name = $child->props['name'] ?? null;
            if ($name === '_usephp_csrf') {
                return true;
            }
        }

        return false;
    }
}
