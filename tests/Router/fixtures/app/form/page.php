<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\Form;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

class FormPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Form Page']);
    }
}
