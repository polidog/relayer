<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Http\OriginGuard;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Auth\ArraySessionStorage;
use Polidog\Rosanna\Defer\DeferredManifestCompiler;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Psx\CompileCommand;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
            $_SERVER['HTTP_X_ORIGIN_SECRET'],
        );
        $_GET = [];
    }

    public function testHandleDeferredShortCircuitsBeforeRoutingWhenRosannaIsWired(): void
    {
        // A deferred wrapper component declared via `deferred(..., new Defer(...))`
        // — `rosanna-defer compile` writes its name + cacheControl into the sidecar
        // deferred-manifest, which `loadComponentManifest()` auto-registers.
        // The defer endpoint must short-circuit normal page routing entirely
        // (no layout, no document wrapper, no 404 from a missing
        // src/Pages/page.psx).
        \file_put_contents(
            $this->workDir . '/src/Components/Greeting.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\Rosanna\Defer\Defer;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                use function Polidog\Rosanna\Defer\deferred;

                return deferred(
                    fn(array $props): Element => <span data-id="greeting">hi {$props['name']}</span>,
                    defer: new Defer(name: 'greeting'),
                );
                PSX,
        );

        $rosanna = $this->bootRosanna();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/greeting?name=Alice';
        $_GET = ['name' => 'Alice'];

        $output = $this->runApp($rosanna);

        self::assertStringNotContainsString('<!DOCTYPE', $output);
        self::assertStringNotContainsString('<html', $output);
        self::assertStringContainsString('<span data-id="greeting">hi Alice</span>', $output);
    }

    public function testAThrowingDeferredComponentAnswers500WithoutItsDetails(): void
    {
        \file_put_contents(
            $this->workDir . '/src/Components/Broken.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\Rosanna\Defer\Defer;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                use function Polidog\Rosanna\Defer\deferred;

                return deferred(
                    function (array $props): Element {
                        throw new \RuntimeException('deferred-secret');
                    },
                    defer: new Defer(name: 'broken'),
                );
                PSX,
        );

        $rosanna = $this->bootRosanna();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/broken';
        $_GET = [];

        \http_response_code(200);
        $output = $this->runApp($rosanna);

        self::assertSame(500, \http_response_code());
        self::assertStringNotContainsString('deferred-secret', $output);
    }

    public function testGuardRunsBeforeDeferredDispatch(): void
    {
        // The deferred endpoint is dispatched before the root middleware, so
        // an origin guard placed in middleware.php could be bypassed with a
        // direct `/_defer/{name}` request. The router's guard must run first:
        // without the secret the component must not execute at all.
        \file_put_contents(
            $this->workDir . '/src/Components/Greeting.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\Rosanna\Defer\Defer;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                use function Polidog\Rosanna\Defer\deferred;

                return deferred(
                    function (array $props): Element {
                        \file_put_contents($props['marker'], 'executed');

                        return <span data-id="greeting">hi</span>;
                    },
                    defer: new Defer(name: 'greeting'),
                );
                PSX,
        );
        $marker = $this->workDir . '/component-ran';
        $guard = OriginGuard::middleware(['secrets' => ['s3cret'], 'logger' => new NullLogger()]);
        $rosanna = $this->bootRosanna();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/greeting?marker=' . \rawurlencode($marker);
        $_GET = ['marker' => $marker];

        $output = $this->runApp($rosanna, guard: $guard);

        self::assertSame(403, \http_response_code());
        self::assertSame('Forbidden', $output);
        self::assertFileDoesNotExist($marker, 'the deferred component must not run for a rejected request');

        // With the secret the same fetch goes through to the component.
        $_SERVER['HTTP_X_ORIGIN_SECRET'] = 's3cret';

        $output = $this->runApp($rosanna, guard: $guard);

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('<span data-id="greeting">hi</span>', $output);
        self::assertFileExists($marker);
    }

    public function testUnregisteredDeferNameReturns404(): void
    {
        // No deferred component registered: GET /_defer/<name> must come back
        // as a 404, not bleed through into normal page routing.
        $rosanna = $this->bootRosanna();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/unknown';

        $output = $this->runApp($rosanna);

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('Deferred component not registered', $output);
    }

    public function testGetRequestPassesThroughToPageRouter(): void
    {
        // No defer URL on a plain GET → handleDeferred returns null and the
        // normal route table runs. Sanity check that wiring Rosanna didn't
        // break ordinary dispatch.
        \file_put_contents(
            $this->workDir . '/src/Pages/page.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Router\Component\PageContext;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                return fn(PageContext $ctx): Closure => fn(): Element => <p>page rendered</p>;
                PSX,
        );

        $rosanna = $this->bootRosanna();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $output = $this->runApp($rosanna);

        self::assertStringContainsString('<p>page rendered</p>', $output);
        self::assertStringContainsString('<!DOCTYPE', $output);
    }

    public function testDeferredComponentReceivesSessionStorageFromContainer(): void
    {
        \file_put_contents(
            $this->workDir . '/src/Components/ProfileBadge.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\Relayer\Auth\SessionStorage;
                use Polidog\Rosanna\Defer\Defer;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                use function Polidog\Rosanna\Defer\deferred;

                return deferred(
                    fn(array $props, SessionStorage $session): Element => <span data-id="profile">{$props['slot']}:{$session->get('viewer')}</span>,
                    defer: new Defer(name: 'profile-badge'),
                );
                PSX,
        );

        $session = new ArraySessionStorage();
        $session->set('viewer', 'Alice');

        $builder = new ContainerBuilder();
        $builder->set(SessionStorage::class, $session);
        $container = new InjectorContainer($builder);

        $rosanna = $this->bootRosanna($container);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/profile-badge?slot=header';
        $_GET = ['slot' => 'header'];

        $output = $this->runApp($rosanna);

        self::assertStringContainsString('<span data-id="profile">header:Alice</span>', $output);
    }

    public function testDeferredComponentReceivesTheRequestFromContainer(): void
    {
        \file_put_contents(
            $this->workDir . '/src/Components/LocaleBadge.psx',
            <<<'PSX'
                <?php
                namespace App\Components;
                use Polidog\Relayer\Http\Request;
                use Polidog\Rosanna\Defer\Defer;
                use Polidog\Rosanna\Html\H;
                use Polidog\Rosanna\Runtime\Element;
                use function Polidog\Rosanna\Defer\deferred;

                return deferred(
                    fn(array $props, Request $request): Element => <span data-id="locale">{$request->path}:{$request->header('x-viewer')}</span>,
                    defer: new Defer(name: 'locale-badge'),
                );
                PSX,
        );

        $builder = new ContainerBuilder();
        // i18n is not under test here, but a container-backed router resolves
        // the locale on every dispatch — bind trivial i18n services so
        // autowiring doesn't go looking for app catalogs.
        $builder->set(LocaleResolver::class, new LocaleResolver(['en'], 'en', pathPrefix: false));
        $builder->set(Translator::class, new Translator([], 'en'));
        $container = new InjectorContainer($builder);
        $rosanna = $this->bootRosanna($container);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_defer/locale-badge';
        $_SERVER['HTTP_X_VIEWER'] = 'Alice';
        $_GET = [];

        $output = $this->runApp($rosanna, $container);

        self::assertStringContainsString('<span data-id="locale">/_defer/locale-badge:Alice</span>', $output);
    }

    private function bootRosanna(?ContainerInterface $container = null): RosannaDefer
    {
        $cacheDir = $this->workDir . '/var/cache/psx';
        \mkdir($cacheDir, 0o777, true);

        // Compile components so the manifest (and deferred-manifest sidecar)
        // is on disk for handleDeferred to resolve registered names at
        // dispatch time.
        \ob_start();

        try {
            $exitCode = (new CompileCommand((new DeferredManifestCompiler())->compile(...)))->run(
                [$this->workDir . '/src/Components', '--cache=' . $cacheDir],
                $this->workDir,
            );
        } finally {
            \ob_end_clean();
        }
        self::assertSame(0, $exitCode, 'PSX component compile failed in test setup');

        $rosanna = new RosannaDefer();
        PsxComponentRegistrar::configure(
            $rosanna,
            componentsDir: $this->workDir . '/src/Components',
            cacheDir: $cacheDir,
            autoCompile: false,
            container: $container,
        );

        return $rosanna;
    }

    private function runApp(RosannaDefer $rosanna, ?InjectorContainer $container = null, ?Closure $guard = null): string
    {
        \http_response_code(200);

        $app = AppRouter::create(
            $this->workDir . '/src/Pages',
            autoCompilePsx: true,
            psxCacheDir: $this->workDir . '/var/cache/psx',
        );
        $app->setRosanna($rosanna);
        if (null !== $guard) {
            $app->setGuard($guard);
        }
        // Mirrors Relayer::boot(): the router and the PSX registrar share one
        // container, which is how a deferred component reaches the per-request
        // Request the router stashes on it.
        if (null !== $container) {
            $app->setContainer($container);
        }

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
