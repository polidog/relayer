<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\GroupApp\Shop\Cart;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

class CartPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Cart Page']);
    }
}
