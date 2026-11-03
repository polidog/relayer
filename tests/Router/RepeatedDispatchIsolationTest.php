<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Document\HtmlDocument;

/**
 * A worker serves many requests from one router, so nothing a request adds to
 * the document may be visible to the next one.
 */
final class RepeatedDispatchIsolationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-repeat-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/a', 0o777, true);
        \mkdir($this->workDir . '/b', 0o777, true);

        \file_put_contents($this->workDir . '/a/page.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;
            return function (PageContext $ctx): Closure {
                $ctx->metadata(['title' => 'Page A', 'description' => 'only-a']);
                $ctx->js('/a-only.js');
                return static fn (): Element => new Element('p', [], ['a']);
            };
            PHP);
        \file_put_contents($this->workDir . '/b/page.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Polidog\Rosanna\Runtime\Element;
            return static fn (): Closure => static fn (): Element => new Element('p', [], ['b']);
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['a', 'b'] as $page) {
            @\unlink($this->workDir . '/' . $page . '/page.php');
            @\rmdir($this->workDir . '/' . $page);
        }
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        $_POST = [];
    }

    public function testAPageScriptDoesNotReachTheNextRequest(): void
    {
        $router = AppRouter::create($this->workDir);

        $this->dispatch($router, '/a');

        self::assertStringNotContainsString('/a-only.js', $this->dispatch($router, '/b'));
    }

    public function testAPageScriptIsEmittedOnceWhenThePageIsServedAgain(): void
    {
        $router = AppRouter::create($this->workDir);

        $this->dispatch($router, '/a');

        self::assertSame(1, \substr_count($this->dispatch($router, '/a'), '/a-only.js'));
    }

    public function testConfigurationOnTheDocumentSurvivesEveryRequest(): void
    {
        $document = HtmlDocument::create()->addCssPath('/app.css');
        $router = AppRouter::create($this->workDir)->setDocument($document);

        $this->dispatch($router, '/a');

        self::assertStringContainsString('/app.css', $this->dispatch($router, '/b'));
    }

    public function testADispatchLeavesTheConfiguredDocumentUntouched(): void
    {
        $document = HtmlDocument::create();
        $router = AppRouter::create($this->workDir)->setDocument($document);

        $this->dispatch($router, '/a');

        self::assertStringNotContainsString('/a-only.js', $document->render(''));
        self::assertStringNotContainsString('only-a', $document->render(''));
    }

    private function dispatch(AppRouter $router, string $path): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;

        \ob_start();
        $router->run();

        return (string) \ob_get_clean();
    }
}
