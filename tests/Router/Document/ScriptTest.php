<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Document;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Document\Script;

final class ScriptTest extends TestCase
{
    public function testPlainSrcRendersBareScriptTag(): void
    {
        self::assertSame(
            '<script src="/app.js"></script>',
            (new Script('/app.js'))->toHtmlTag(),
        );
    }

    public function testFlagsAreEmittedInModuleDeferAsyncOrder(): void
    {
        $script = new Script('/app.js', defer: true, async: true, module: true);

        self::assertSame(
            '<script src="/app.js" type="module" defer async></script>',
            $script->toHtmlTag(),
        );
    }

    public function testSrcIsHtmlAttributeEscaped(): void
    {
        $script = new Script('/a.js?x="y"&z=<1>');

        self::assertSame(
            '<script src="/a.js?x=&quot;y&quot;&amp;z=&lt;1&gt;"></script>',
            $script->toHtmlTag(),
        );
    }

    public function testEmptySrcIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Script('   ');
    }
}
