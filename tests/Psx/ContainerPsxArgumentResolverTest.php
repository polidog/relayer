<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Tests\Fixtures\PlainService;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ContainerPsxArgumentResolverTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-psx-args-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/Components', 0o777, true);
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->workDir));
    }

    public function testAServiceComesFromTheContainer(): void
    {
        $html = $this->renderComponent('fn(array $props, \\' . PlainService::class . ' $s) => <p>{$s::class}</p>');

        self::assertSame('<p>' . PlainService::class . '</p>', $html);
    }

    public function testADefaultBeforeAServiceKeepsThePositionsAligned(): void
    {
        $html = $this->renderComponent('fn(array $props, string $d = "dflt", ?\\' . PlainService::class . ' $s = null) => <p>{$d}|{$s::class}</p>');

        self::assertSame('<p>dflt|' . PlainService::class . '</p>', $html);
    }

    public function testANullableServiceTheContainerLacksIsNull(): void
    {
        $html = $this->renderComponent('fn(array $props, ?\Countable $c) => <p>{$c === null ? "none" : "some"}</p>');

        self::assertSame('<p>none</p>', $html);
    }

    public function testAServiceWithADefaultTheContainerLacksUsesTheDefault(): void
    {
        $html = $this->renderComponent('fn(array $props, ?\Countable $c = null, string $d = "x") => <p>{$d}</p>');

        self::assertSame('<p>x</p>', $html);
    }

    public function testARequiredServiceTheContainerLacksNamesTheParameter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot autowire PSX component parameter $c');

        $this->renderComponent('fn(array $props, \Countable $c) => <p>x</p>');
    }

    public function testADeferredComponentGetsItsServiceAtTheEndpoint(): void
    {
        \file_put_contents($this->workDir . '/Components/Late.psx', '<?php
            namespace App\Components;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(fn(array $props, \\' . PlainService::class . ' $s, ?\Countable $c = null) => <b>{$props["q"]}:{$s::class}</b>, new Defer(name: "late"));');

        $response = $this->configure()->handleDeferred(new DeferRequest('GET', '/_defer/late', ['q' => 'Z']));

        self::assertNotNull($response);
        self::assertSame('<b>Z:' . PlainService::class . '</b>', $response->body);
    }

    private function renderComponent(string $component): string
    {
        \file_put_contents(
            $this->workDir . '/Components/Thing.psx',
            "<?php\nnamespace App\\Components;\nreturn {$component};\n",
        );

        $app = $this->configure();

        return RenderContext::run(
            $app->scope(),
            static function () use ($app): string {
                $element = RenderContext::scope()->psx->render('App\Components\Thing');
                self::assertInstanceOf(Element::class, $element);

                return $app->renderElement($element);
            },
        );
    }

    private function configure(): RosannaDefer
    {
        $app = new RosannaDefer();
        PsxComponentRegistrar::configure(
            $app,
            componentsDir: $this->workDir . '/Components',
            cacheDir: $this->workDir . '/cache',
            autoCompile: true,
            container: new class implements ContainerInterface {
                public function has(string $id): bool
                {
                    return PlainService::class === $id;
                }

                public function get(string $id): object
                {
                    if (PlainService::class !== $id) {
                        throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
                    }

                    return new PlainService();
                }
            },
        );

        return $app;
    }
}
