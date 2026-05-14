<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Http\Cache;
use Polidog\UsePhp\Runtime\Element;

#[Cache(maxAge: 60, public: true, etagKey: 'cached-page-key')]
final class DynamicEtagPage extends PageComponent
{
    public function render(): Element
    {
        throw new \LogicException('not rendered in tests');
    }
}
