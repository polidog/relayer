<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\ResolvesItself;
use ReflectionClass;
use ReflectionProperty;

/**
 * The `Renderable` children of a page tree on a POST that submits an action
 * one of them registered. The Renderer would render them only in the final
 * render, after dispatch, so they are rendered here instead: once before
 * dispatch so their actions are registered, and once more for the response.
 *
 * The response render runs the page's render closure again, so its children
 * are new instances with the props of that render. A property the dispatched
 * action changed on a child of the first render is copied onto the child of
 * the same class at the same place in the new tree before it renders, so
 * state the action kept on `$this` (validation errors, say) reaches the
 * response. Readonly properties are props and are never copied.
 *
 * Children are walked in the order the Renderer reaches them: arrays
 * flattened, `resolve()` before `render()`, and of a `__defer__` placeholder
 * only the fallback, which is what the page shows.
 *
 * @internal used by PageRenderer on the pending-action POST path
 */
final class RenderableChildren
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $properties = [];

    /** @var array<string, Renderable> the children of the first render, by place */
    private array $children = [];

    /** @var array<string, array<string, mixed>> their state right after rendering */
    private array $states = [];

    private function __construct() {}

    /**
     * Render every `Renderable` in the tree, so the actions they register
     * exist before the request is dispatched. Call it before dispatch.
     */
    public static function render(Element $tree): self
    {
        $rendered = new self();
        self::resolveElement($tree, '', static function (string $place, Renderable $child) use ($rendered): void {
            $rendered->children[$place] = $child;
        });

        foreach ($rendered->children as $place => $child) {
            $rendered->states[$place] = self::state($child);
        }

        return $rendered;
    }

    /**
     * Render every `Renderable` in the response tree, carrying over what the
     * dispatched action changed on the children of {@see render()}. The tree
     * returned has no `Renderable` left in it. Call it after dispatch.
     */
    public function resolve(Element $tree): Element
    {
        $changes = [];
        foreach ($this->children as $place => $child) {
            $changed = self::changedSince($child, $this->states[$place] ?? []);
            if ([] !== $changed) {
                $changes[$place] = [$child, $changed];
            }
        }

        return self::resolveElement($tree, '', static function (string $place, Renderable $child) use ($changes): void {
            if (!isset($changes[$place])) {
                return;
            }

            [$before, $changed] = $changes[$place];
            if ($before === $child || $before::class !== $child::class) {
                return;
            }

            $properties = self::properties($child);
            foreach ($changed as $key => $value) {
                $properties[$key]->setRawValue($child, $value);
            }
        });
    }

    /**
     * @param Closure(string, Renderable): void $beforeRender
     */
    private static function resolveElement(Element $element, string $place, Closure $beforeRender): Element
    {
        $props = $element->props;
        $fallback = $props['__fallback'] ?? null;
        if ('__defer__' === $element->type && $fallback instanceof Element) {
            $props['__fallback'] = self::resolveElement($fallback, $place . '/fallback', $beforeRender);
        }

        $children = [];
        foreach (self::flatten($element->children) as $index => $child) {
            $childPlace = $place . '/' . $index;

            if ($child instanceof Renderable) {
                $beforeRender($childPlace, $child);
                $child = $child instanceof ResolvesItself ? $child->resolve() : $child->render();
            }

            $children[] = $child instanceof Element ? self::resolveElement($child, $childPlace, $beforeRender) : $child;
        }

        return new Element($element->type, $props, $children);
    }

    /**
     * @param array<mixed> $children
     *
     * @return list<mixed>
     */
    private static function flatten(array $children): array
    {
        $flat = [];
        foreach ($children as $child) {
            if (\is_array($child)) {
                \array_push($flat, ...self::flatten($child));
            } else {
                $flat[] = $child;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $before
     *
     * @return array<string, mixed>
     */
    private static function changedSince(Renderable $child, array $before): array
    {
        $changed = [];
        foreach (self::state($child) as $key => $value) {
            if (!\array_key_exists($key, $before) || $before[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(Renderable $child): array
    {
        $state = [];
        foreach (self::properties($child) as $key => $property) {
            if ($property->isInitialized($child)) {
                $state[$key] = $property->getRawValue($child);
            }
        }

        return $state;
    }

    /**
     * The properties an action can change: not static, not readonly and not
     * virtual, including the private ones of parent classes.
     *
     * @return array<string, ReflectionProperty>
     */
    private static function properties(Renderable $child): array
    {
        $class = $child::class;
        if (isset(self::$properties[$class])) {
            return self::$properties[$class];
        }

        $properties = [];
        for ($reflection = new ReflectionClass($child); false !== $reflection; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->class !== $reflection->name
                    || $property->isStatic()
                    || $property->isReadOnly()
                    || $property->isVirtual()
                ) {
                    continue;
                }

                $properties[$reflection->name . '::' . $property->name] = $property;
            }
        }

        return self::$properties[$class] = $properties;
    }
}
