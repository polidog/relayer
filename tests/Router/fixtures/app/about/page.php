<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\About;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

class AboutPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['About Page']);
    }
}
