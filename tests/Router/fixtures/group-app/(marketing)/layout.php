<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\GroupApp\Marketing;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Layout\LayoutComponent;

class MarketingLayout extends LayoutComponent
{
    public function render(): Element
    {
        return new Element('div', ['className' => 'marketing-layout'], [
            $this->getChildren(),
        ]);
    }
}
