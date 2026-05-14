<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Psx\Compiler;
use RuntimeException;

final class PsxIntegrationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/psx-approuter-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testLoadPageThrowsWhenCompiledPsxMissing(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\Relayer\\Router\\Component\\PageContext;\n"
            . "return fn(PageContext \$ctx) => fn() => 'irrelevant';\n",
        );

        $app = AppRouter::create($this->workDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compiled PSX not found');
        $this->runApp($app, '/');
    }

    public function testAutoCompileGeneratesCacheFileAndPageRenders(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            <<<'PSX'
            <?php
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;
            use Polidog\Relayer\Router\Component\PageContext;

            return function (PageContext $ctx) {
                $ctx->metadata(['title' => 'PSX Home']);
                return function (): Element {
                    return <div><h1>Auto-compiled</h1></div>;
                };
            };
            PSX,
        );

        $cacheDir = $this->workDir . '/cache';
        $app = AppRouter::create(
            $this->workDir,
            autoCompilePsx: true,
            psxCacheDir: $cacheDir,
        );

        $output = $this->runApp($app, '/');

        // The cache dir should now contain a sha1-named compiled file.
        self::assertDirectoryExists($cacheDir);
        $expected = CompileCommand::cachePathFor(
            $cacheDir,
            $this->workDir . '/page.psx',
        );
        self::assertFileExists($expected);
        self::assertFileDoesNotExist(
            $this->workDir . '/page.psx.php',
            'Source tree must NOT contain a sibling .psx.php',
        );
        self::assertStringContainsString('Auto-compiled', $output);
    }

    public function testStaleCacheIsTreatedAsAuthoritativeWithoutAutoCompile(): void
    {
        // Production-mode contract: once `usephp compile` produced a cache
        // file, the runtime treats it as the source of truth. Editing the
        // .psx after deploy without re-running compile must NOT silently
        // pick up the new source — it must keep serving the cache.
        \file_put_contents(
            $this->workDir . '/page.psx',
            <<<'PSX'
            <?php
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;
            use Polidog\Relayer\Router\Component\PageContext;

            return function (PageContext $ctx) {
                return function (): Element {
                    return <p>Cached version</p>;
                };
            };
            PSX,
        );

        $cacheDir = $this->workDir . '/cache';
        \mkdir($cacheDir, 0o755, true);
        $compiler = new Compiler();
        $cachePath = CompileCommand::cachePathFor(
            $cacheDir,
            $this->workDir . '/page.psx',
        );
        $psxSource = (string) \file_get_contents($this->workDir . '/page.psx');
        \file_put_contents(
            $cachePath,
            $compiler->compile($psxSource),
        );

        // Edit the .psx so the source is now newer than the cache.
        \sleep(1); // ensure mtime advances on coarse filesystems
        \file_put_contents(
            $this->workDir . '/page.psx',
            \str_replace('Cached version', 'Live edit', $psxSource),
        );
        \touch($this->workDir . '/page.psx', \time() + 60);

        $app = AppRouter::create($this->workDir, psxCacheDir: $cacheDir);
        $output = $this->runApp($app, '/');

        self::assertStringContainsString('Cached version', $output);
        self::assertStringNotContainsString('Live edit', $output);
    }

    public function testPrecompiledPsxIsLoadedFromCacheDirWithoutAutoCompile(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            <<<'PSX'
            <?php
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;
            use Polidog\Relayer\Router\Component\PageContext;

            return function (PageContext $ctx) {
                return function (): Element {
                    return <p>Pre-compiled output</p>;
                };
            };
            PSX,
        );

        $cacheDir = $this->workDir . '/cache';
        \mkdir($cacheDir, 0o755, true);

        $compiler = new Compiler();
        $compiled = $compiler->compile((string) \file_get_contents($this->workDir . '/page.psx'));
        $cachePath = CompileCommand::cachePathFor(
            $cacheDir,
            $this->workDir . '/page.psx',
        );
        \file_put_contents($cachePath, $compiled);

        $app = AppRouter::create($this->workDir, psxCacheDir: $cacheDir);

        $output = $this->runApp($app, '/');
        self::assertStringContainsString('Pre-compiled output', $output);
    }

    private function runApp(AppRouter $app, string $path): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
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
