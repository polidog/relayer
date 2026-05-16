<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use LogicException;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\UsePhp\Runtime\Element;

final class ScriptablePage extends PageComponent
{
    public function render(): Element
    {
        throw new LogicException('not rendered in tests');
    }

    public function js(
        string $src,
        bool $defer = false,
        bool $async = false,
        bool $module = false,
    ): void {
        $this->addJs($src, defer: $defer, async: $async, module: $module);
    }
}
