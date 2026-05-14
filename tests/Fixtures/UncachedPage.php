<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\UsePhp\Runtime\Element;

final class UncachedPage extends PageComponent
{
    public function render(): Element
    {
        throw new \LogicException('not rendered in tests');
    }
}
