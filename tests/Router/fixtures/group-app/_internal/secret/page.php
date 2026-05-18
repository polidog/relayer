<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\GroupApp\Internal\Secret;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

// `_internal` is a private folder; nothing beneath it is a route.
class SecretPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Secret Page']);
    }
}
