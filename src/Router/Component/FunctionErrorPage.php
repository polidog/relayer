<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use Polidog\Relayer\Router\Dispatch\InvalidErrorPage;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/**
 * A function-style `error.psx` (`return fn (array $props): Element => ...;`)
 * wrapped so the error path renders it like a class-style error page.
 *
 * @internal
 */
final readonly class FunctionErrorPage implements Renderable
{
    /**
     * @param Closure(): mixed $renderFn
     */
    public function __construct(
        private Closure $renderFn,
        private string $errorPath,
    ) {}

    public function render(): Element
    {
        $element = ($this->renderFn)();

        if (!$element instanceof Element) {
            throw new InvalidErrorPage(\sprintf(
                'The render closure of the function-style error page %s must return an Element, got %s.',
                $this->errorPath,
                \get_debug_type($element),
            ));
        }

        return $element;
    }
}
