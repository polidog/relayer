<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\ApiConflict;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\UsePhp\Runtime\Element;

class ConflictPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['conflict']);
    }
}
