<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

class HomePage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Home Page']);
    }
}
