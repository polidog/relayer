<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\GroupApp\Marketing\Draft;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

// Lives under a `_private` folder — must never be routed.
class DraftPage extends PageComponent
{
    public function render(): Element
    {
        return new Element('div', [], ['Draft Page']);
    }
}
