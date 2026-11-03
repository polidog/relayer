<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Closure;
use Polidog\Rosanna\Runtime\DeferredRenderer;
use Polidog\Rosanna\Runtime\Element;

/**
 * Remembers whether a render produced a defer placeholder, which is what makes
 * a page need the client script when the document loads it only on such pages.
 */
final class TrackingDeferredRenderer implements DeferredRenderer
{
    private bool $rendered = false;

    public function __construct(private readonly DeferredRenderer $inner) {}

    public function renderDeferred(Element $element, Closure $render): string
    {
        $this->rendered = true;

        return $this->inner->renderDeferred($element, $render);
    }

    public function hasRendered(): bool
    {
        return $this->rendered;
    }
}
