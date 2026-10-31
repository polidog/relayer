<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Form\FormActionTransformer;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

final class FormActionTransformerTest extends TestCase
{
    public function testNullNodeIsReturnedAsNull(): void
    {
        self::assertNull(FormActionTransformer::apply(null, '/x'));
    }

    public function testStringNodeIsReturnedUnchanged(): void
    {
        self::assertSame('hello', FormActionTransformer::apply('hello', '/x'));
    }

    public function testNullChildrenAreFilteredFromOutput(): void
    {
        // PSX conditional rendering (`{$cond ? <el> : null}`) lets nulls
        // sneak into the children array even though Element's phpdoc
        // declares the array contract more strictly. The transformer must
        // tolerate that without throwing.
        /** @phpstan-ignore argument.type */
        $node = new Element('div', [], [
            new Element('p', [], ['kept']),
            null,
            'literal-string',
            null,
        ]);

        $result = FormActionTransformer::apply($node, '/x');

        self::assertInstanceOf(Element::class, $result);
        self::assertCount(2, $result->children);
        self::assertInstanceOf(Element::class, $result->children[0]);
        self::assertSame('literal-string', $result->children[1]);
    }

    public function testNullChildrenInsideFormAreFiltered(): void
    {
        // Regression guard for the original failure mode: the recursive
        // descent must not bubble a null through to the form normalizer
        // either.
        /** @phpstan-ignore argument.type */
        $form = new Element('form', ['method' => 'post', 'action' => '/submit'], [
            new Element('input', ['name' => 'email'], []),
            null,
            new Element('button', ['type' => 'submit'], ['Send']),
        ]);

        $result = FormActionTransformer::apply($form, '/submit');

        self::assertInstanceOf(Element::class, $result);
        self::assertCount(2, $result->children);
    }

    #[DataProvider('provideNumericChildrenBecomeTextCases')]
    public function testNumericChildrenBecomeText(float|int $child, string $expected): void
    {
        $result = FormActionTransformer::apply(self::psxElement('p', ['Count: ', $child]), '/x');

        self::assertInstanceOf(Element::class, $result);
        self::assertSame(['Count: ', $expected], $result->children);
    }

    /**
     * @return iterable<string, array{float|int, string}>
     */
    public static function provideNumericChildrenBecomeTextCases(): iterable
    {
        yield 'int' => [42, '42'];

        yield 'zero is not treated as empty' => [0, '0'];

        yield 'negative int' => [-3, '-3'];

        yield 'float' => [1.5, '1.5'];

        yield 'float zero is not treated as empty' => [0.0, '0'];
    }

    public function testBooleanChildrenAreDropped(): void
    {
        $node = self::psxElement('p', ['before', true, false, 'after']);

        $result = FormActionTransformer::apply($node, '/x');

        self::assertInstanceOf(Element::class, $result);
        self::assertSame(['before', 'after'], $result->children);
    }

    public function testScalarChildrenInsideFormAreNormalized(): void
    {
        $form = H::__callStatic('form', [
            'method' => 'post',
            'action' => '/submit',
            'children' => [7, false, new Element('button', ['type' => 'submit'], ['Send'])],
        ]);

        $result = FormActionTransformer::apply($form, '/submit');

        self::assertInstanceOf(Element::class, $result);
        self::assertCount(2, $result->children);
        self::assertSame('7', $result->children[0]);
    }

    public function testScalarChildrenRenderThroughLayoutRenderer(): void
    {
        // The whole render path: the state-action scan and usePHP's Renderer
        // both run after the transformer and must accept what it returns.
        $page = new Element('div', [], [
            self::psxElement('p', ['Count: ', 42]),
            self::psxElement('p', ['Zero: ', 0]),
            self::psxElement('p', ['Price: ', 1.5]),
            self::psxElement('p', ['Flags:', true, false]),
        ]);

        $html = new LayoutRenderer('c', '/x')->render($page, new LayoutStack());

        self::assertSame(
            '<div><p>Count: 42</p><p>Zero: 0</p><p>Price: 1.5</p><p>Flags:</p></div>',
            $html,
        );
    }

    /**
     * Builds an element the way compiled PSX does, which leaves `{expr}`
     * scalars in the children unchecked.
     *
     * @param list<bool|float|int|string> $children
     */
    private static function psxElement(string $type, array $children): Element
    {
        return H::__callStatic($type, ['children' => $children]);
    }
}
