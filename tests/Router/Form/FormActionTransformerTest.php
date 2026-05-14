<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Form\FormActionTransformer;
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
}
