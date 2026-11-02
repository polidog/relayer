<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\NativeApp;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Rosanna\Runtime\Element;

class HomePage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Home']);
    }
}
