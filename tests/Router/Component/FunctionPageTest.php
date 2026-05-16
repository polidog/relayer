<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

final class FunctionPageTest extends TestCase
{
    public function testGetCacheReturnsNullWhenContextHasNoPolicy(): void
    {
        $page = new FunctionPage(
            static fn () => self::fail('render should not run'),
            new PageContext(),
            '/test',
        );

        self::assertNull($page->getCache());
    }

    public function testGetCacheReturnsPolicySetOnContext(): void
    {
        $context = new PageContext();
        $cache = new Cache(maxAge: 30, etagKey: 'feed');
        $context->cache($cache);

        $page = new FunctionPage(
            static fn () => self::fail('render should not run'),
            $context,
            '/test',
        );

        self::assertSame($cache, $page->getCache());
    }

    public function testGetScriptsPassesThroughContext(): void
    {
        $context = new PageContext();
        $context->js('/chart.js', defer: true);

        $page = new FunctionPage(
            static fn () => self::fail('render should not run'),
            $context,
            '/test',
        );

        self::assertSame($context->getScripts(), $page->getScripts());
    }

    public function testRenderInvokesFactoryClosure(): void
    {
        $element = new Element('p', [], ['hello']);

        $page = new FunctionPage(
            static fn () => $element,
            new PageContext(),
            '/test',
        );

        self::assertSame($element, $page->render());
    }
}
