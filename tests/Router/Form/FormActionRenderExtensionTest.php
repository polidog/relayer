<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Rosanna\Defer\PlaceholderRenderer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use RuntimeException;

final class FormActionRenderExtensionTest extends TestCase
{
    public function testAFormInARenderableChildGetsTheActionAndCsrfFields(): void
    {
        $token = FormAction::create('Page', 'save');
        $child = new class($token) implements Renderable {
            public function __construct(private readonly string $token) {}

            public function render(): Element
            {
                return new Element('form', ['action' => $this->token], ['body']);
            }
        };

        $html = $this->render(new Element('div', [], [$child]));

        self::assertStringContainsString('action="/here"', $html);
        self::assertStringContainsString('name="_rosanna_csrf"', $html);
        self::assertStringContainsString('name="_rosanna_action"', $html);
        self::assertStringNotContainsString('action="rosanna-action:', $html);
    }

    public function testAFormInADeferFallbackIsNormalized(): void
    {
        $token = FormAction::create('Page', 'save');

        $html = $this->render(H::defer('late', fallback: new Element('form', ['action' => $token], [])));

        self::assertStringNotContainsString('action="rosanna-action:', $html);
    }

    public function testAFormWithATokenPostsBackWithItsHiddenFieldsFirst(): void
    {
        $token = FormAction::create('Page', 'save');

        $html = $this->render(new Element('form', ['action' => $token], [new Element('input', ['name' => 'email'], [])]));

        self::assertStringStartsWith('<form action="/here" method="post" data-rosanna-form="1"><input type="hidden" name="_rosanna_csrf"', $html);
        self::assertStringContainsString('<input type="hidden" name="_rosanna_action" value="' . $token . '" /><input name="email" /></form>', $html);
    }

    public function testHiddenFieldsTheFormAlreadyHasAreNotAddedAgain(): void
    {
        $token = FormAction::create('Page', 'save');

        $html = $this->render(new Element('form', ['action' => $token, 'method' => 'get'], [
            [new Element('input', ['name' => '_rosanna_action', 'value' => $token], [])],
            new Element('input', ['name' => '_rosanna_csrf', 'value' => 'mine'], []),
        ]));

        self::assertStringContainsString('method="get"', $html);
        self::assertSame(1, \substr_count($html, '_rosanna_action'));
        self::assertSame(1, \substr_count($html, '_rosanna_csrf'));
    }

    public function testAFormWithAPlainActionIsLeftAlone(): void
    {
        $html = $this->render(new Element('form', ['action' => '/search'], ['q']));

        self::assertSame('<form action="/search">q</form>', $html);
    }

    public function testVoidTagsStaySelfClosing(): void
    {
        self::assertSame('<div><br /></div>', $this->render(new Element('div', [], [new Element('br', [], [])])));
    }

    public function testScalarAndNestedChildrenRenderLikeRosanna(): void
    {
        $html = $this->render(new Element('div', [], ['a ', [1, 2, true, null], 0, 1.5, false]));

        self::assertSame('<div>a 1201.5</div>', $html);
    }

    public function testAnEventBindingWithoutTheStateLayerStillFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('polidog/rosanna-state');

        $this->render(new Element('button', ['wire:click' => 'x'], []));
    }

    private function render(Element $element): string
    {
        return (new LayoutRenderer('/here', new PlaceholderRenderer('/_defer')))->render($element, new LayoutStack());
    }
}
