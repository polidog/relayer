<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\UsePhp\UsePHP;

final class PsxComponentRegistrarTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-psx-registrar-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testConfigureReturnsNullWhenComponentsDirAbsent(): void
    {
        $app = new UsePHP();

        $result = PsxComponentRegistrar::configure(
            $app,
            componentsDir: $this->workDir . '/does-not-exist',
            cacheDir: $this->workDir . '/cache',
            autoCompile: true,
        );

        self::assertNull($result);
    }

    public function testConfigureCompilesAndLoadsManifestInDevMode(): void
    {
        $componentsDir = $this->workDir . '/Components';
        $cacheDir = $this->workDir . '/cache';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents(
            $componentsDir . '/Greeting.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;
                return fn(array $props): Element => <span>hi</span>;
                PSX,
        );

        $app = new UsePHP();
        $app->setSnapshotSecret('test-secret');

        $manifestPath = PsxComponentRegistrar::configure(
            $app,
            componentsDir: $componentsDir,
            cacheDir: $cacheDir,
            autoCompile: true,
        );

        self::assertNotNull($manifestPath);
        self::assertFileExists($manifestPath);
        // Verify the manifest got loaded into UsePHP by rendering through it.
        $element = $app->renderPsxComponent('App\Components\Greeting');
        self::assertSame('span', $element->type);
    }

    public function testNeedsCompileReturnsTrueWhenManifestAbsent(): void
    {
        $componentsDir = $this->workDir . '/Components';
        \mkdir($componentsDir, 0o777, true);

        self::assertTrue(
            PsxComponentRegistrar::needsCompile($componentsDir, $this->workDir . '/no-manifest.php'),
        );
    }

    public function testNeedsCompileReturnsTrueWhenSourceNewerThanManifest(): void
    {
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents($manifestPath, '<?php return [];');
        \touch($manifestPath, \time() - 60);

        \file_put_contents($componentsDir . '/Greeting.psx', "<?php\nreturn fn() => null;\n");

        self::assertTrue(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
    }

    public function testNeedsCompileReturnsFalseWhenManifestFresh(): void
    {
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents($componentsDir . '/Greeting.psx', "<?php\nreturn fn() => null;\n");
        \touch($componentsDir . '/Greeting.psx', \time() - 60);

        \file_put_contents($manifestPath, '<?php return [];');

        self::assertFalse(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
    }

    public function testNeedsCompileReturnsTrueWhenDeferSourcePresentButSidecarMissing(): void
    {
        // Cache produced by use-php < 0.4.0 (manifest.php newer than every
        // source, but no deferred-manifest.php sidecar). If any .psx
        // declares a Defer, we must recompile so the sidecar is generated
        // and `loadComponentManifest()` can auto-register the endpoint.
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents(
            $componentsDir . '/Deferred.psx',
            <<<'PSX'
                <?php
                use Polidog\UsePhp\Component\Defer;
                use function Polidog\UsePhp\Runtime\fc;
                return fc(fn() => null, defer: new Defer(name: 'x'));
                PSX,
        );
        \touch($componentsDir . '/Deferred.psx', \time() - 60);
        \file_put_contents($manifestPath, '<?php return [];');

        self::assertTrue(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
    }

    public function testNeedsCompileReturnsFalseWhenSidecarMissingButNoDeferSource(): void
    {
        // A project without any deferred components has manifest.php but no
        // deferred-manifest.php — sidecar absence is the steady state, not a
        // signal to recompile.
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents($componentsDir . '/Plain.psx', "<?php\nreturn fn() => null;\n");
        \touch($componentsDir . '/Plain.psx', \time() - 60);
        \file_put_contents($manifestPath, '<?php return [];');

        self::assertFalse(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
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
