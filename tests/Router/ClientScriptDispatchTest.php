<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Document\HtmlDocument;

/**
 * Every page loads the defer client script unless the app opts in to loading
 * it only with a placeholder, and a page is otherwise exactly what its layouts
 * and page rendered.
 */
final class ClientScriptDispatchTest extends TestCase
{
    private const CLIENT_SCRIPT = '<script src="/rosanna-defer.js"></script>';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-client-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/plain', 0o777, true);
        \mkdir($this->workDir . '/deferred', 0o777, true);

        \file_put_contents($this->workDir . '/plain/page.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Polidog\Rosanna\Runtime\Element;
            return static fn (): Closure => static fn (): Element => new Element('p', [], ['plain']);
            PHP);
        \file_put_contents($this->workDir . '/deferred/page.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Polidog\Rosanna\Html\H;
            use Polidog\Rosanna\Runtime\Element;
            return static fn (): Closure => static fn (): Element => H::defer('late');
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['plain', 'deferred'] as $page) {
            @\unlink($this->workDir . '/' . $page . '/page.php');
            @\rmdir($this->workDir . '/' . $page);
        }
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testAPageWithoutADeferLoadsTheClientScriptByDefault(): void
    {
        self::assertStringContainsString(self::CLIENT_SCRIPT, $this->dispatch(AppRouter::create($this->workDir), '/plain'));
    }

    public function testAPageWithADeferLoadsTheClientScriptByDefault(): void
    {
        self::assertStringContainsString(self::CLIENT_SCRIPT, $this->dispatch(AppRouter::create($this->workDir), '/deferred'));
    }

    public function testTheOptInLeavesTheScriptOffAPageWithoutADefer(): void
    {
        self::assertStringNotContainsString(self::CLIENT_SCRIPT, $this->dispatch($this->onDemandRouter(), '/plain'));
    }

    public function testTheOptInLoadsTheScriptOnAPageWithADefer(): void
    {
        self::assertStringContainsString(self::CLIENT_SCRIPT, $this->dispatch($this->onDemandRouter(), '/deferred'));
    }

    public function testTheNeedForTheScriptIsDecidedPerRequest(): void
    {
        $router = $this->onDemandRouter();

        $this->dispatch($router, '/deferred');
        self::assertStringNotContainsString(self::CLIENT_SCRIPT, $this->dispatch($router, '/plain'));
        self::assertStringContainsString(self::CLIENT_SCRIPT, $this->dispatch($router, '/deferred'));
    }

    public function testARequestDoesNotChangeTheConfiguredDocument(): void
    {
        $document = HtmlDocument::create()->loadClientScriptOnlyWithDefer();

        $this->dispatch(AppRouter::create($this->workDir)->setDocument($document), '/plain');

        self::assertStringContainsString(self::CLIENT_SCRIPT, $document->render('<p>unreported</p>'));
    }

    public function testThePageIsNotWrappedInAFrameworkElement(): void
    {
        $html = $this->dispatch(AppRouter::create($this->workDir), '/plain');

        self::assertStringNotContainsString('data-rosanna=', $html);
        self::assertStringContainsString("<body>\n    <p>plain</p>", $html);
    }

    private function onDemandRouter(): AppRouter
    {
        return AppRouter::create($this->workDir)->setDocument(HtmlDocument::create()->loadClientScriptOnlyWithDefer());
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
