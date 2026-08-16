<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `RELAYER_WARM_CACHE` — the opt-in that lets a production boot build the
 * artifacts a deploy step would normally precompile.
 *
 * It exists for deployments where the build path is not the runtime path,
 * the FrankenPHP single binary being the motivating case: it unpacks its
 * embedded app into a fresh directory on start, so a `.psx` cache keyed by
 * absolute source paths and a container dump full of absolute paths are
 * useless. Warming produces both at runtime instead.
 *
 * Each test runs in its own process: the warm path `require`s the dumped
 * container class, which can only be declared once per process.
 */
final class RelayerWarmCacheTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/relayer-warm-' . \uniqid();
        \mkdir($this->projectRoot . '/src/Pages', 0o755, true);
        \file_put_contents(
            $this->projectRoot . '/src/Pages/page.php',
            "<?php\n\nreturn static fn (): string => 'home';\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectRoot);
    }

    #[RunInSeparateProcess]
    public function testWarmBuildsTheRouteAndContainerArtifactsThatTheDeployDidNotShip(): void
    {
        $this->writeEnv("APP_ENV=prod\nRELAYER_WARM_CACHE=1\n");

        $router = Relayer::boot($this->projectRoot);

        $routes = $this->projectRoot . '/' . Relayer::COMPILED_ROUTES_FILE;
        $container = $this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE;

        self::assertFileExists($routes);
        self::assertFileExists($container);

        // The route artifact must be the real thing the prod router loads,
        // not an empty placeholder: the scanned page has to be in it.
        self::assertStringContainsString("'pattern' => '/'", (string) \file_get_contents($routes));

        // The container the boot returned must be the dumped one — the
        // warm path writes it and then loads it, so this request and every
        // later request (which take the plain load path) run identical
        // wiring rather than a live build that merely resembles the dump.
        self::assertTrue(\class_exists(Relayer::COMPILED_CONTAINER_CLASS, false));

        $inner = (new ReflectionProperty(InjectorContainer::class, 'container'))
            ->getValue(Relayer::container())
        ;
        self::assertIsObject($inner);
        self::assertSame(Relayer::COMPILED_CONTAINER_CLASS, $inner::class);

        // Compiled `.psx` is keyed by absolute source path, so the binary
        // can never ship a usable one — warm mode must let the router
        // compile pages on demand instead of failing the request.
        self::assertTrue(
            (new ReflectionProperty(AppRouter::class, 'autoCompilePsx'))->getValue($router),
        );
    }

    #[RunInSeparateProcess]
    public function testWarmStillServesTheRequestWhenTheArtifactCannotBeProduced(): void
    {
        $this->writeEnv("APP_ENV=prod\nRELAYER_WARM_CACHE=1\n");

        // Occupy the container cache directory's path with a file so the
        // dump cannot be written. That is the reachable half of "warm-up
        // failed"; the other half (dump() rejecting a multi-file PhpDumper
        // result) needs a container no test can realistically build, but
        // both converge on the same fall-through, so this pins the
        // contract they share.
        $containerDir = \dirname($this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE);
        \mkdir(\dirname($containerDir), 0o755, true);
        \file_put_contents($containerDir, 'not a directory');

        $router = Relayer::boot($this->projectRoot);

        // Warm-up is best effort: failing to produce the artifact must
        // cost speed, never availability.
        self::assertInstanceOf(AppRouter::class, $router);
        self::assertFileDoesNotExist($this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE);
        self::assertFalse(\class_exists(Relayer::COMPILED_CONTAINER_CLASS, false));

        // ...and the container it fell back to is a live build, which is
        // the whole point: the request is served with real wiring rather
        // than a half-written dump.
        $inner = (new ReflectionProperty(InjectorContainer::class, 'container'))
            ->getValue(Relayer::container())
        ;
        self::assertInstanceOf(ContainerBuilder::class, $inner);
    }

    #[RunInSeparateProcess]
    public function testProdWithoutWarmKeepsThePresenceGatedContract(): void
    {
        $this->writeEnv("APP_ENV=prod\n");

        $router = Relayer::boot($this->projectRoot);

        // Nothing is written: a missing artifact stays a deploy-step
        // decision, and a missing compiled `.psx` stays a loud error
        // rather than a silent recompile that masks a broken build.
        self::assertFileDoesNotExist($this->projectRoot . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertFileDoesNotExist($this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE);
        self::assertFalse(
            (new ReflectionProperty(AppRouter::class, 'autoCompilePsx'))->getValue($router),
        );
    }

    #[RunInSeparateProcess]
    public function testWarmIsIgnoredInDevWhichAlwaysLiveBuilds(): void
    {
        $this->writeEnv("APP_ENV=dev\nRELAYER_WARM_CACHE=1\n");

        Relayer::boot($this->projectRoot);

        // Dev must never read (or write) a dump: config edits have to take
        // effect on the next request, which a compiled artifact defeats.
        self::assertFileDoesNotExist($this->projectRoot . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertFileDoesNotExist($this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE);
    }

    private function writeEnv(string $contents): void
    {
        \file_put_contents($this->projectRoot . '/.env', $contents);
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->rrmdir($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
