<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;

final class PageContextTest extends TestCase
{
    public function testCacheDefaultsToNull(): void
    {
        self::assertNull((new PageContext())->getCache());
    }

    public function testCacheStoresAndExposesPolicy(): void
    {
        $context = new PageContext();
        $cache = new Cache(maxAge: 60, etagKey: 'home');

        $context->cache($cache);

        self::assertSame($cache, $context->getCache());
    }
}
