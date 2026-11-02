<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\ResolvesItself;

/**
 * A child the renderer expands through `resolve()` rather than `render()`,
 * wrapping an {@see ActionChild}.
 */
final readonly class ResolvingActionChild implements ResolvesItself
{
    public function __construct(private ActionChild $inner) {}

    public function render(): Element
    {
        return $this->inner->render();
    }

    public function resolve(): Element
    {
        return new Element('section', ['data-resolved' => '1'], [$this->inner->render()]);
    }
}
