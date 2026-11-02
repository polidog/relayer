<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

final class ContainerPsxComponentTest extends TestCase
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

    public function testAServiceAsTheFirstParameterComesFromTheContainer(): void
    {
        $html = $this->renderComponent('fn(\\' . PlainService::class . ' $s) => <p>{$s::class}</p>');

        self::assertSame('<p>' . PlainService::class . '</p>', $html);
    }

    public function testANullableFirstServiceTheContainerLacksIsNull(): void
    {
        $html = $this->renderComponent('fn(?\Countable $c) => <p>{$c === null ? "none" : "some"}</p>');

        self::assertSame('<p>none</p>', $html);
    }

    public function testAServiceFirstDeferredComponentGetsItsServiceAtTheEndpoint(): void
    {
        \file_put_contents($this->workDir . '/Components/Late.psx', '<?php
            namespace App\Components;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(fn(\\' . PlainService::class . ' $s) => <b>{$s::class}</b>, new Defer(name: "late"));');

        $response = $this->configure()->handleDeferred(new DeferRequest('GET', '/_defer/late'));

        self::assertNotNull($response);
        self::assertSame('<b>' . PlainService::class . '</b>', $response->body);
    }

    public function testAServiceFirstDeferredComponentIsAPlaceholderOnThePage(): void
    {
        \file_put_contents($this->workDir . '/Components/Late.psx', '<?php
            namespace App\Components;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(fn(\\' . PlainService::class . ' $s) => <b>{$s::class}</b>, new Defer(name: "late"));');

        $html = $this->renderFqcn($this->configure(), 'App\Components\Late');

        self::assertStringContainsString('/_defer/late', $html);
        self::assertStringNotContainsString(PlainService::class, $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileDeclaringAFunctionSurvivesAReflectedDefault(): void
    {
        $html = $this->renderFile('function helper(): string { return "h"; }
            return fn(array $props, string $d = "dflt") => <p>{helper()}|{$d}</p>;');

        self::assertSame('<p>h|dflt</p>', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileDeclaringAClassSurvivesAReflectedDefault(): void
    {
        $html = $this->renderFile('final class Helper { public const string NAME = "h"; }
            return fn(array $props, string $d = "dflt") => <p>{Helper::NAME}|{$d}</p>;');

        self::assertSame('<p>h|dflt</p>', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileDeclaringAFunctionSurvivesASecondBootInTheProcess(): void
    {
        $file = 'function helper(): string { return "h"; }
            return fn(array $props, string $d = "dflt") => <p>{helper()}|{$d}</p>;';

        self::assertSame('<p>h|dflt</p>', $this->renderFile($file));
        self::assertSame('<p>h|dflt</p>', $this->renderFqcn($this->configure(), 'App\Components\Thing'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADeferredFileDeclaringAFunctionSurvivesTheCompileInTheSameProcess(): void
    {
        // Compiling looks for Defer declarations by executing the compiled
        // file, and here the same process then renders it.
        \file_put_contents($this->workDir . '/Components/Late.psx', '<?php
            namespace App\Components;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            function helper(): string { return "h"; }
            return deferred(fn(array $props, ?\Countable $c = null) => <b>{helper()}</b>, new Defer(name: "late"));');

        $response = $this->configure()->handleDeferred(new DeferRequest('GET', '/_defer/late'));

        self::assertNotNull($response);
        self::assertSame('<b>h</b>', $response->body);
    }

    public function testTheCompiledDeferredManifestKeepsTheCachePolicy(): void
    {
        \file_put_contents($this->workDir . '/Components/Late.psx', '<?php
            namespace App\Components;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(fn(array $props) => <b>x</b>, new Defer(name: "late", cacheControl: "private, max-age=7"));');

        $response = $this->configure()->handleDeferred(new DeferRequest('GET', '/_defer/late'));

        self::assertNotNull($response);
        self::assertSame('private, max-age=7', $response->headers['Cache-Control'] ?? null);
    }

    public function testTwoComponentsWithOneDeferredNameFailTheCompile(): void
    {
        foreach (['One', 'Two'] as $component) {
            \file_put_contents($this->workDir . "/Components/{$component}.psx", '<?php
                namespace App\Components;
                use Polidog\Rosanna\Defer\Defer;
                use function Polidog\Rosanna\Defer\deferred;
                return deferred(fn(array $props) => <b>x</b>, new Defer(name: "same"));');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PSX component compile failed');

        $this->configure();
    }

    public function testACompiledFileRunsOnceAcrossRendersAndBoots(): void
    {
        $log = $this->workDir . '/executions.log';
        $file = '\file_put_contents(' . \var_export($log, true) . ', "x", \FILE_APPEND);
            return fn(array $props, string $d = "dflt") => <p>{$d}</p>;';

        self::assertSame('<p>dflt</p>', $this->renderFile($file));

        $app = $this->configure();
        $renders = [$this->renderFqcn($app, 'App\Components\Thing'), $this->renderFqcn($app, 'App\Components\Thing')];

        self::assertSame(['<p>dflt</p>', '<p>dflt</p>'], $renders);
        self::assertSame('x', \file_get_contents($log));
    }

    public function testARecompiledFileIsLoadedAgain(): void
    {
        self::assertSame('<p>old</p>', $this->renderComponent('fn(array $props) => <p>old</p>'));

        \file_put_contents(
            $this->workDir . '/Components/Thing.psx',
            "<?php\nnamespace App\\Components;\nreturn fn(array \$props) => <p>new</p>;\n",
        );
        \touch($this->workDir . '/Components/Thing.psx', \time() + 10);

        self::assertSame('<p>new</p>', $this->renderFqcn($this->configure(), 'App\Components\Thing'));
    }

    public function testACompiledFileHasNoClassScope(): void
    {
        $html = $this->renderFile('$probe = function (): void {};
            $scope = (new \ReflectionFunction($probe))->getClosureScopeClass()?->name ?? "none";
            return fn(array $props) => <p>{$scope}</p>;');

        self::assertSame('<p>none</p>', $html);
    }

    private function renderFile(string $body): string
    {
        \file_put_contents(
            $this->workDir . '/Components/Thing.psx',
            "<?php\nnamespace App\\Components;\n{$body}\n",
        );

        return $this->renderFqcn($this->configure(), 'App\Components\Thing');
    }

    private function renderFqcn(RosannaDefer $app, string $fqcn): string
    {
        return RenderContext::run(
            $app->scope(),
            static function () use ($app, $fqcn): string {
                $element = RenderContext::scope()->psx->render($fqcn);
                self::assertInstanceOf(Element::class, $element);

                return $app->renderElement($element);
            },
        );
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
