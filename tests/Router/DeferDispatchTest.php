<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

final class DeferDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-defer-' . \uniqid();
        \mkdir($this->workDir . '/src/Pages', 0o777, true);
        \mkdir($this->workDir . '/src/Components', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_POST['_usephp_defer_payload'],
            $_POST['_usephp_defer_sig'],
        );
        RenderContext::clearApp();
    }

    public function testHandleDeferredShortCircuitsBeforeRoutingWhenUsePhpIsWired(): void
    {
        // A trivial fragment component returns plain Element HTML. We
        // dispatch via the defer path so the router must NEVER touch the
        // page tree (no layout, no document wrapper, no 404 from a missing
        // src/Pages/page.psx).
        \file_put_contents(
            $this->workDir . '/src/Components/Greeting.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(array $props): Element => <span data-id="greeting">hi {$props['name']}</span>;
                PSX,
        );

        $usephp = $this->bootUsePhp();

        $payload = \json_encode(['fqcn' => 'App\Components\Greeting', 'props' => ['name' => 'Alice']], \JSON_THROW_ON_ERROR);
        $sig = $usephp->getSnapshotSerializer()->signString($payload);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/some/page'; // Same URL as the host page; defer ignores it.
        $_POST['_usephp_defer_payload'] = $payload;
        $_POST['_usephp_defer_sig'] = $sig;

        $output = $this->runApp($usephp);

        self::assertStringNotContainsString('<!DOCTYPE', $output);
        self::assertStringNotContainsString('<html', $output);
        self::assertStringContainsString('<span data-id="greeting">hi Alice</span>', $output);
    }

    public function testInvalidSignatureReturns400(): void
    {
        \file_put_contents(
            $this->workDir . '/src/Components/Greeting.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(array $props): Element => <span>secret</span>;
                PSX,
        );

        $usephp = $this->bootUsePhp();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST['_usephp_defer_payload'] = '{"fqcn":"App\\\Components\\\Greeting","props":[]}';
        $_POST['_usephp_defer_sig'] = 'bogus-signature';

        $output = $this->runApp($usephp);

        self::assertSame(400, \http_response_code());
        self::assertStringContainsString('Invalid defer signature', $output);
    }

    public function testGetRequestPassesThroughToPageRouter(): void
    {
        // No defer payload on a GET → handleDeferred returns null and the
        // normal route table runs. Sanity check that wiring UsePHP didn't
        // break ordinary dispatch.
        \file_put_contents(
            $this->workDir . '/src/Pages/page.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(PageContext $ctx): Closure => fn(): Element => <p>page rendered</p>;
                PSX,
        );

        $usephp = $this->bootUsePhp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $output = $this->runApp($usephp);

        self::assertStringContainsString('<p>page rendered</p>', $output);
        self::assertStringContainsString('<!DOCTYPE', $output);
    }

    private function bootUsePhp(): UsePHP
    {
        $cacheDir = $this->workDir . '/var/cache/psx';
        \mkdir($cacheDir, 0o777, true);

        // Compile components so the manifest is on disk for handleDeferred
        // to resolve App\Components\... FQCNs at dispatch time.
        \ob_start();

        try {
            $exitCode = (new CompileCommand())->run(
                [$this->workDir . '/src/Components', '--cache=' . $cacheDir],
                $this->workDir,
            );
        } finally {
            \ob_end_clean();
        }
        self::assertSame(0, $exitCode, 'PSX component compile failed in test setup');

        $usephp = new UsePHP();
        $usephp->setSnapshotSecret('defer-test-secret');
        if (\file_exists($cacheDir . '/manifest.php')) {
            $usephp->loadComponentManifest($cacheDir . '/manifest.php');
        }

        return $usephp;
    }

    private function runApp(UsePHP $usephp): string
    {
        \http_response_code(200);

        $app = AppRouter::create(
            $this->workDir . '/src/Pages',
            autoCompilePsx: true,
            psxCacheDir: $this->workDir . '/var/cache/psx',
        );
        $app->setUsePhp($usephp);

        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
