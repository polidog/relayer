<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Tests\Fixtures\PlainService;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

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
        $app = new RosannaDefer();

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
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                return fn(array $props): Element => <span>hi</span>;
                PSX,
        );

        $app = new RosannaDefer();

        $manifestPath = PsxComponentRegistrar::configure(
            $app,
            componentsDir: $componentsDir,
            cacheDir: $cacheDir,
            autoCompile: true,
        );

        self::assertNotNull($manifestPath);
        self::assertFileExists($manifestPath);
        // Verify the manifest got loaded into Rosanna by rendering through it.
        $element = self::renderComponent($app, 'App\Components\Greeting');
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

    public function testNeedsCompileReturnsTrueWhenCompilerVersionChanged(): void
    {
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents($manifestPath, '<?php return [];');
        self::assertTrue(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));

        \file_put_contents($manifestPath . '.version', '0');
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
        \file_put_contents(\dirname($manifestPath) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME, '<?php return [];');
        \file_put_contents($manifestPath . '.version', (string) CompileCommand::CACHE_VERSION);

        self::assertFalse(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
    }

    public function testNeedsCompileReturnsTrueWhenDeferredManifestMissing(): void
    {
        // Every compile writes it and RosannaDefer refuses a manifest without
        // one, so its absence means a cache from another compiler.
        $componentsDir = $this->workDir . '/Components';
        $manifestPath = $this->workDir . '/manifest.php';
        \mkdir($componentsDir, 0o777, true);

        \file_put_contents($componentsDir . '/Plain.psx', "<?php\nreturn fn() => null;\n");
        \touch($componentsDir . '/Plain.psx', \time() - 60);
        \file_put_contents($manifestPath, '<?php return [];');
        \file_put_contents($manifestPath . '.version', (string) CompileCommand::CACHE_VERSION);

        self::assertTrue(PsxComponentRegistrar::needsCompile($componentsDir, $manifestPath));
    }

    public function testContainerAwareComponentUsesManifestParameterMetadata(): void
    {
        $componentsDir = $this->workDir . '/Components';
        $cacheDir = $this->workDir . '/cache';
        \mkdir($componentsDir, 0o777, true);
        \mkdir($cacheDir, 0o777, true);

        $compiledPath = $cacheDir . '/Greeting.php';
        \file_put_contents(
            $compiledPath,
            <<<'PHP'
                <?php
                declare(strict_types=1);

                use Polidog\Relayer\Tests\Fixtures\PlainService;
                use Polidog\Rosanna\Runtime\Element;

                return static fn (array $props, PlainService $service): Element => new Element(
                    'span',
                    [],
                    [$props['label'] . ':' . $service::class],
                );
                PHP,
        );

        \file_put_contents(
            $cacheDir . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME,
            '<?php return ' . \var_export([PsxRenderer::BUILD_ID_KEY => 'test'], true) . ';',
        );
        \file_put_contents(
            $cacheDir . '/manifest.php',
            '<?php return ' . \var_export([
                PsxRenderer::BUILD_ID_KEY => 'test',
                'App\Components\Greeting' => [
                    'file' => $compiledPath,
                    'parameters' => [
                        ['kind' => 'props', 'name' => 'props'],
                        ['kind' => 'service', 'name' => 'service', 'service' => PlainService::class],
                    ],
                ],
            ], true) . ';',
        );

        $plain = new PlainService();
        $container = new class($plain) implements ContainerInterface {
            public function __construct(private readonly PlainService $plain) {}

            public function has(string $id): bool
            {
                return PlainService::class === $id;
            }

            public function get(string $id): object
            {
                if (PlainService::class !== $id) {
                    throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
                }

                return $this->plain;
            }
        };

        $app = new RosannaDefer();
        PsxComponentRegistrar::configure($app, $componentsDir, $cacheDir, autoCompile: false, container: $container);

        $element = self::renderComponent($app, 'App\Components\Greeting', ['label' => 'hello']);

        self::assertInstanceOf(Element::class, $element);
        self::assertSame(['hello:' . PlainService::class], $element->children);
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function renderComponent(RosannaDefer $app, string $fqcn, array $props = []): Element
    {
        return RenderContext::run(
            $app->scope(),
            static fn (): Element => RenderContext::scope()->psx->render($fqcn, $props),
        );
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
