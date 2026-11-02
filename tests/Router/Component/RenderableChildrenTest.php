<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Component\RenderableChildren;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

final class RenderableChildrenTest extends TestCase
{
    public function testWhatTheActionChangedIsCopiedOntoTheChildAtTheSamePlace(): void
    {
        $before = new RenderableChildrenTestChild('old prop', 'old counter');
        $children = RenderableChildren::render(new Element('main', [], [[$before]]));

        // What an action does to the child between the two renders.
        $before->fail('required');

        $after = new RenderableChildrenTestChild('new prop', 'new counter');
        $tree = $children->resolve(new Element('main', [], [$after]));

        self::assertSame(
            '<div data-prop="new prop" data-counter="new counter">required</div>',
            self::html($tree),
            'the changed error is copied, the readonly prop and the unchanged property are not',
        );
    }

    public function testAChildOfAnotherClassAtThatPlaceIsLeftAlone(): void
    {
        $before = new RenderableChildrenTestChild('prop', 'counter');
        $children = RenderableChildren::render(new Element('main', [], [$before]));
        $before->fail('required');

        $tree = $children->resolve(new Element('main', [], [new RenderableChildrenTestOther()]));

        self::assertSame('<p>other</p>', self::html($tree));
    }

    public function testChildrenOfChildrenAndDeferFallbacksAreResolved(): void
    {
        $inner = new RenderableChildrenTestChild('inner', 'c');
        $children = RenderableChildren::render(new Element('main', [], [
            new RenderableChildrenTestWrapper($inner),
        ]));
        $inner->fail('nested');

        $tree = $children->resolve(new Element('main', [], [
            new RenderableChildrenTestWrapper(new RenderableChildrenTestChild('inner', 'c')),
            new Element('__defer__', ['__fallback' => new Element('div', [], [new RenderableChildrenTestOther()])]),
        ]));

        self::assertSame('<section><div data-prop="inner" data-counter="c">nested</div></section>', self::html($tree->children[0]));
        $defer = $tree->children[1];
        self::assertInstanceOf(Element::class, $defer);
        self::assertSame('<div><p>other</p></div>', self::html($defer->props['__fallback'] ?? null));
    }

    private static function html(mixed $node): string
    {
        if (!$node instanceof Element) {
            return \is_string($node) ? $node : '';
        }

        $children = \implode('', \array_map(self::html(...), $node->children));
        if ('main' === $node->type) {
            return $children;
        }

        $attributes = '';
        foreach ($node->props as $name => $value) {
            $attributes .= \sprintf(' %s="%s"', $name, \is_string($value) ? $value : '');
        }

        return \sprintf('<%s%s>%s</%s>', $node->type, $attributes, $children, $node->type);
    }
}

abstract class RenderableChildrenTestBase implements Renderable
{
    private string $error = '';

    public function fail(string $error): void
    {
        $this->error = $error;
    }

    protected function error(): string
    {
        return $this->error;
    }
}

final class RenderableChildrenTestChild extends RenderableChildrenTestBase
{
    public function __construct(
        private readonly string $prop,
        private string $counter,
    ) {}

    public function render(): Element
    {
        return new Element('div', ['data-prop' => $this->prop, 'data-counter' => $this->counter], [$this->error()]);
    }
}

final class RenderableChildrenTestOther implements Renderable
{
    public function render(): Element
    {
        return new Element('p', [], ['other']);
    }
}

final readonly class RenderableChildrenTestWrapper implements Renderable
{
    public function __construct(private Renderable $inner) {}

    public function render(): Element
    {
        return new Element('section', [], [$this->inner]);
    }
}
