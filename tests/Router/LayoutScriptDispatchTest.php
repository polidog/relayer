<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;

/**
 * Regression: a layout's render() only runs inside LayoutRenderer, which
 * happens after the page renders. Script collection must therefore happen
 * after layout rendering — otherwise addJs() called from inside a layout's
 * render() (the documented usage) is silently dropped.
 */
final class LayoutScriptDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $token = \bin2hex(\random_bytes(6));
        $this->workDir = \sys_get_temp_dir() . '/layoutjs-' . $token;
        \mkdir($this->workDir, 0o777, true);

        // Unique namespace per test: the class-style loader uses require_once
        // and a fixed class name would clash across the dir each test creates.
        $namespace = 'Polidog\Relayer\Tests\Tmp\LayoutJs\T' . $token;

        \file_put_contents($this->workDir . '/layout.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Rosanna\Runtime\Element;
            class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    $this->addJs('/from-layout.js');
                    return new Element('div', [], [$this->getChildren()]);
                }
            }
            PHP, $namespace));

        \file_put_contents($this->workDir . '/page.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Rosanna\Runtime\Element;
            class HomePage extends PageComponent
            {
                public function render(): Element
                {
                    $this->addJs('/from-page.js', defer: true);
                    return new Element('h1', [], ['hi']);
                }
            }
            PHP, $namespace));

        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->workDir . '/layout.php');
        @\unlink($this->workDir . '/page.php');
        @\rmdir($this->workDir);
    }

    public function testLayoutDeclaredScriptInRenderIsEmittedBeforeThePageScript(): void
    {
        $html = $this->runApp('/');

        $rosanna = \strpos($html, '<script src="/rosanna-defer.js">');
        $layout = \strpos($html, '<script src="/from-layout.js">');
        $page = \strpos($html, '<script src="/from-page.js" defer>');

        self::assertNotFalse($layout, 'layout addJs() inside render() must be emitted');
        self::assertNotFalse($page);
        self::assertNotFalse($rosanna);
        self::assertLessThan($layout, $rosanna);
        self::assertLessThan($page, $layout);
    }

    private function runApp(string $path): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \ob_start();

        try {
            AppRouter::create($this->workDir)->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }
}
