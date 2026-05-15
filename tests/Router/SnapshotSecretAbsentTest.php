<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use LogicException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

/**
 * Regression coverage for the `getSnapshotSerializer()` LogicException swallow
 * in {@see AppRouter} (added with the use-php 0.5.0 upgrade).
 *
 * use-php 0.5.0 made `getSnapshotSerializer()` throw a LogicException when no
 * secret is configured, where it previously returned an unsigned serializer.
 * Relayer only configures a secret when `USEPHP_SNAPSHOT_SECRET` is set (or, in
 * dev, via a per-project fallback), so a prod app without the env var
 * legitimately has none. AppRouter catches that one probe and degrades to a
 * null serializer. This test pins both halves of that contract:
 *
 *  (a) a page with no Snapshot-storage component renders normally without a
 *      secret — the catch must not turn an ordinary request into a 500;
 *  (b) the catch is narrowly scoped to the probe only — a component that
 *      actually serializes Snapshot state without a secret still fails loudly
 *      (use-php's LogicException propagates out of dispatch rather than being
 *      swallowed and silently producing forgeable unsigned state).
 *
 * Neither helper calls `setSnapshotSecret()`, mirroring the prod-without-env
 * configuration the upgrade changed.
 */
final class SnapshotSecretAbsentTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-nosecret-' . \uniqid();
        \mkdir($this->workDir . '/src/Pages', 0o777, true);
        \mkdir($this->workDir . '/src/Components', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        RenderContext::clearApp();
    }

    public function testNonSnapshotPageRendersWithoutSnapshotSecret(): void
    {
        // Plain function page, no Snapshot storage anywhere. Before the catch,
        // AppRouter would have called getSnapshotSerializer() unconditionally
        // and use-php 0.5.0 would have thrown — turning every page on a
        // secret-less prod deploy into a 500. It must render normally instead.
        \file_put_contents(
            $this->workDir . '/src/Pages/page.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(PageContext $ctx): Closure => fn(): Element => <p>no secret, still fine</p>;
                PSX,
        );

        $usephp = $this->bootUsePhpWithoutSecret();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $output = $this->runApp($usephp);

        self::assertStringContainsString('<p>no secret, still fine</p>', $output);
        self::assertStringContainsString('<!DOCTYPE', $output);
    }

    public function testSnapshotComponentFailsLoudlyWithoutSnapshotSecret(): void
    {
        // A component that opts into Snapshot storage and emits an action
        // (onClick → wire:click) forces use-php's renderWithForm path to
        // serialize a snapshot. With no secret the serializer is null and
        // use-php throws a LogicException. The catch in AppRouter is scoped
        // to the getSnapshotSerializer() probe only, NOT the render call, so
        // that exception must surface rather than be silently swallowed into
        // forgeable unsigned state.
        \file_put_contents(
            $this->workDir . '/src/Components/SnapWidget.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                use Polidog\UsePhp\Storage\StorageType;
                use function Polidog\UsePhp\Runtime\fc;
                use function Polidog\UsePhp\Runtime\useState;

                return fc(
                    function (array $props): Element {
                        [$n, $setN] = useState(0);
                        return <button onClick={fn() => $setN($n + 1)}>{(string) $n}</button>;
                    },
                    'snap-widget',
                    StorageType::Snapshot,
                );
                PSX,
        );
        \file_put_contents(
            $this->workDir . '/src/Pages/page.psx',
            <<<'PSX'
                <?php
                use App\Components\SnapWidget;
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(PageContext $ctx): Closure => fn(): Element => <div><SnapWidget /></div>;
                PSX,
        );

        $usephp = $this->bootUsePhpWithoutSecret();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(LogicException::class);

        $this->runApp($usephp);
    }

    private function bootUsePhpWithoutSecret(): UsePHP
    {
        $cacheDir = $this->workDir . '/var/cache/psx';
        \mkdir($cacheDir, 0o777, true);

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

        // Deliberately NO setSnapshotSecret() — this is the prod-without-env
        // configuration the 0.5.0 upgrade changed the behavior of.
        $usephp = new UsePHP();
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
