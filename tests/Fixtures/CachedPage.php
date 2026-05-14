<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Http\Cache;
use Polidog\UsePhp\Runtime\Element;

#[Cache(maxAge: 3600, public: true, vary: ['Accept-Language'], etag: 'home-v1')]
final class CachedPage extends PageComponent
{
    public function render(): Element
    {
        // Test fixture; never actually rendered.
        throw new \LogicException('not rendered in tests');
    }
}
