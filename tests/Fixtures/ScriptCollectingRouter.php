<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\UsePhp\Component\ComponentInterface;

final class ScriptCollectingRouter extends AppRouter
{
    /**
     * @return array<int, Script>
     */
    public function collectScriptsFor(
        ComponentInterface|FunctionPage $page,
        LayoutStack $layouts,
    ): array {
        return $this->collectScripts($page, $layouts);
    }
}
