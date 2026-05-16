<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Tests\Fixtures\ScriptableLayout;
use Polidog\Relayer\Tests\Fixtures\ScriptablePage;
use Polidog\Relayer\Tests\Fixtures\ScriptCollectingRouter;

final class AppRouterCollectScriptsTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/scripts-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        @\rmdir($this->workDir);
    }

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
            $this->srcs($this->router()->collectScriptsFor($page, $stack)),
        );
    }

    public function testPageScriptsCollectedWithNoLayouts(): void
    {
        $page = new ScriptablePage();
        $page->js('/only.js');

        self::assertSame(
            ['/only.js'],
            $this->srcs($this->router()->collectScriptsFor($page, new LayoutStack())),
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

    private function router(): ScriptCollectingRouter
    {
        return new ScriptCollectingRouter($this->workDir);
    }
}
