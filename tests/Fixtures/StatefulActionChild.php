<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/**
 * A `Renderable` child whose action keeps its result on `$this`, the way a
 * form keeps its validation errors, next to a readonly prop the page passes
 * in on every render.
 */
final class StatefulActionChild implements Renderable
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly string $name,
        private readonly string $label = '',
    ) {}

    public function render(): Element
    {
        $token = PageContext::current()->action($this->name, function (array $form): void {
            ActionChild::$dispatched[] = [$this->name, $form];

            if ('' === ($form['title'] ?? '')) {
                $this->errors[] = 'title is required';
            }
        });

        return new Element('form', ['action' => $token, 'data-child' => $this->name], [
            new Element('p', ['class' => 'label'], [$this->label]),
            ...\array_map(static fn (string $error): Element => new Element('p', ['class' => 'error'], [$error]), $this->errors),
        ]);
    }
}
