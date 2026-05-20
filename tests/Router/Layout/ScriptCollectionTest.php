<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Layout;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Layout\ScriptCollection;
use Polidog\Relayer\Tests\Fixtures\ScriptableLayout;
use Polidog\Relayer\Tests\Fixtures\ScriptablePage;

/**
 * Replaces the previous `AppRouterCollectScriptsTest`: now that
 * AppRouter is `final`, the script-gathering rule lives in
 * {@see ScriptCollection} and is testable directly. Same coverage,
 * just no subclassing AppRouter to reach the private hook.
 */
final class ScriptCollectionTest extends TestCase
{
    public function testScriptsAreCollectedRootLayoutThenInnerThenPage(): void
    {
        $root = new ScriptableLayout();
        $root->js('/root.js');

        $inner = new ScriptableLayout();
        $inner->js('/inner.js');

        // Route::layoutPaths is documented root-to-deepest, and LayoutStack
        // preserves push order, so all() is [root, inner].
        $stack = new LayoutStack();
        $stack->push($root);
        $stack->push($inner);

        $page = new ScriptablePage();
        $page->js('/page.js');

        self::assertSame(
            ['/root.js', '/inner.js', '/page.js'],
            $this->srcs(ScriptCollection::gather($page, $stack)),
        );
    }

    public function testPageScriptsCollectedWithNoLayouts(): void
    {
        $page = new ScriptablePage();
        $page->js('/only.js');

        self::assertSame(
            ['/only.js'],
            $this->srcs(ScriptCollection::gather($page, new LayoutStack())),
        );
    }

    /**
     * @param array<int, Script> $scripts
     *
     * @return array<int, string>
     */
    private function srcs(array $scripts): array
    {
        return \array_map(static fn (Script $s): string => $s->src, $scripts);
    }
}
