<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Layout;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Tests\Fixtures\ScriptableLayout;

final class LayoutComponentScriptsTest extends TestCase
{
    public function testGetScriptsDefaultsToEmpty(): void
    {
        self::assertSame([], (new ScriptableLayout())->getScripts());
    }

    public function testAddJsAccumulatesScripts(): void
    {
        $layout = new ScriptableLayout();
        $layout->js('/layout.js', async: true);

        $scripts = $layout->getScripts();

        self::assertCount(1, $scripts);
        self::assertSame('<script src="/layout.js" async></script>', $scripts[0]->toHtmlTag());
    }
}
