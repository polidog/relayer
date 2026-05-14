<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\UsePhp\Runtime\Element;

interface LayoutInterface
{
    public function render(): Element;

    /**
     * @param array<Element|string>|Element|string $children
     */
    public function setChildren(array|Element|string $children): void;
}
