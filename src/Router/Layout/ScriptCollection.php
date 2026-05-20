<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Layout;

use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Document\Script;
use Polidog\UsePhp\Component\ComponentInterface;

/**
 * Gathers `<script>` declarations from a page + its layout stack in
 * emission order (outer layout → inner layouts → page). Extracted from
 * {@see AppRouter} so the rule is testable in
 * isolation now that AppRouter is `final` and its internals are private.
 *
 * Only {@see LayoutComponent} / {@see PageComponent} / {@see FunctionPage}
 * carry scripts — the same instanceof asymmetry `setParams()` and
 * metadata already have for raw {@see LayoutInterface} implementers. No
 * deduplication: a layout and a page both declaring the same src yields
 * two tags by design (mirrors the metadata contract: declare, don't
 * reconcile).
 */
final class ScriptCollection
{
    /**
     * @return array<int, Script>
     */
    public static function gather(ComponentInterface|FunctionPage $page, LayoutStack $layouts): array
    {
        $scripts = [];

        foreach ($layouts->all() as $layout) {
            if ($layout instanceof LayoutComponent) {
                foreach ($layout->getScripts() as $script) {
                    $scripts[] = $script;
                }
            }
        }

        if ($page instanceof FunctionPage || $page instanceof PageComponent) {
            foreach ($page->getScripts() as $script) {
                $scripts[] = $script;
            }
        }

        return $scripts;
    }
}
