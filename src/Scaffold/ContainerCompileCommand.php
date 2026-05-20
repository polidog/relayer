<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Relayer;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

/**
 * `relayer container:compile` — dump the Symfony DI container to a plain
 * PHP class so production boots skip the per-request ContainerBuilder
 * build + `compile()`.
 *
 * The container counterpart to {@see RoutesCompileCommand}: run it once at
 * deploy and {@see Relayer::boot()} `require`s
 * {@see Relayer::COMPILED_CONTAINER_FILE} instead of rebuilding the
 * container on every request. Same testable shape as the other scaffold
 * commands (injected line writer + cwd, no STDOUT/chdir coupling).
 *
 * It reproduces the *production* container exactly: `.env` is loaded the
 * same way {@see Relayer::boot()} loads it (so an `AppConfigurator` that
 * reads env compiles the shape it will run with), the project's
 * `App\AppConfigurator` is discovered by the scaffolded convention, and
 * the container is built with `isDev: false` (no Traceable* decorators —
 * dev never reads the dump anyway). A build or dump failure is reported
 * here, at deploy, rather than on the first production request.
 *
 * ## Env-derived parameters are baked at build time
 *
 * The compile runs the configurator once, at deploy. Anything an
 * `AppConfigurator::configure()` reads from `$_ENV` / `$_SERVER` /
 * `getenv()` and passes to `setParameter()` as a plain string is **frozen
 * into the dumped class**. The dump never re-runs the configurator, so an
 * env var that is only injected **at runtime** (Fly secrets, Cloud Run env,
 * sidecar injectors, …) and was absent or empty during compile will
 * resolve to that build-time value in prod — typically an empty string,
 * which then silently falls through downstream "is this configured?"
 * checks.
 *
 * For secrets that exist only at runtime, hand Symfony the placeholder
 * itself instead of the resolved value:
 *
 * ```php
 * // bakes "" if the env is empty at compile time — the trap
 * $container->setParameter('app.api_token', $_ENV['API_TOKEN'] ?? '');
 *
 * // dumped as a getEnv('API_TOKEN') call — resolves at load time
 * $container->setParameter('app.api_token', '%env(API_TOKEN)%');
 * ```
 *
 * `%env(int:…)%`, `%env(bool:…)%`, `%env(default::…)%` etc. give typed /
 * fallback resolution. See README "Runtime secrets vs container:compile".
 * `routes:compile` is unaffected — it only scans `src/Pages/` and has no
 * env coupling.
 */
final class ContainerCompileCommand
{
    /** The scaffolded convention: `App\` is PSR-4-mapped to `src/`. */
    private const APP_CONFIGURATOR = 'App\AppConfigurator';

    /**
     * @param list<string>               $args  argv after the `container:compile` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 on a build/dump/write failure
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');

        // Mirror Relayer::loadEnv so an AppConfigurator that reads env
        // compiles the same container shape it will boot with in prod.
        if (\file_exists($root . '/.env')) {
            (new Dotenv())->loadEnv($root . '/.env');
        }

        try {
            $configurator = self::discoverConfigurator($root, $write);
            // isDev: false — the dump is only ever read by prod boot.
            // forDump: true — keep `%env(VAR)%` placeholders intact so
            // PhpDumper can wire them to per-request `getEnv()` calls in
            // the dumped class. Without this the live env values would be
            // baked into the dump and runtime env changes would be ignored.
            $container = ContainerFactory::create($root, $configurator, false, forDump: true);

            $class = Relayer::COMPILED_CONTAINER_CLASS;
            $sep = \strrpos($class, '\\');
            $dumped = (new PhpDumper($container))->dump([
                'class' => false === $sep ? $class : \substr($class, $sep + 1),
                'namespace' => false === $sep ? '' : \substr($class, 0, $sep),
            ]);
        } catch (Throwable $e) {
            $write('Could not compile the container: ' . $e->getMessage());
            $write('Fix the service configuration (config/services.yaml or App\AppConfigurator), then retry.');

            return 1;
        }

        if (!\is_string($dumped)) {
            $write('Container dumper returned multiple files; this command expects a single class.');

            return 1;
        }

        $outFile = $root . '/' . Relayer::COMPILED_CONTAINER_FILE;
        $outDir = \dirname($outFile);

        if (!\is_dir($outDir) && !@\mkdir($outDir, 0o775, true) && !\is_dir($outDir)) {
            $write("Could not create {$outDir}.");

            return 1;
        }

        if (false === @\file_put_contents($outFile, $dumped)) {
            $write("Could not write {$outFile}.");

            return 1;
        }

        $write('Compiled DI container to ' . Relayer::COMPILED_CONTAINER_FILE);
        $write('Prod boot will use it; `relayer container:compile` again after changing services.');
        // Visible at deploy, where the deployer sees it before the silent
        // failure ever has a chance to happen in prod. The class docblock
        // explains the trap in full.
        $write('Note: env values bake at build time. Use %env(VAR)% in AppConfigurator for runtime-injected secrets (Fly/CloudRun/…).');

        return 0;
    }

    /**
     * Instantiate the project's `App\AppConfigurator` when it is
     * autoloadable and a real {@see AppConfigurator}, exactly as the
     * scaffolded `public/index.php` does. Absent (an app with no custom
     * services) → null, and {@see ContainerFactory::create()} builds the
     * framework-default container.
     *
     * @param Closure(string): void $write
     */
    private static function discoverConfigurator(string $root, Closure $write): ?AppConfigurator
    {
        $fqcn = self::APP_CONFIGURATOR;

        if (!\class_exists($fqcn) || !\is_subclass_of($fqcn, AppConfigurator::class)) {
            $write('No App\AppConfigurator found — compiling the framework-default container.');

            return null;
        }

        // is_subclass_of already proved the type; a bad/incompatible
        // constructor surfaces as a Throwable caught by run()'s build
        // guard, reported as a deploy-time failure.
        return new $fqcn($root);
    }
}
