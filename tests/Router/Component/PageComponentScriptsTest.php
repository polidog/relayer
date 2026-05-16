<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Tests\Fixtures\ScriptablePage;

final class PageComponentScriptsTest extends TestCase
{
    public function testGetScriptsDefaultsToEmpty(): void
    {
        self::assertSame([], (new ScriptablePage())->getScripts());
    }

    public function testAddJsAccumulatesScriptsInCallOrderWithFlags(): void
    {
        $page = new ScriptablePage();
        $page->js('/a.js');
        $page->js('/b.js', defer: true, module: true);

        $scripts = $page->getScripts();

        self::assertCount(2, $scripts);
        self::assertSame('<script src="/a.js"></script>', $scripts[0]->toHtmlTag());
        self::assertSame('<script src="/b.js" type="module" defer></script>', $scripts[1]->toHtmlTag());
    }
}
