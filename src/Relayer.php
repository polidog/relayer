<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\TraceableAppRouter;
use Polidog\UsePhp\UsePHP;
use Symfony\Component\Dotenv\Dotenv;

/**
 * One-shot bootstrapper for usePHP applications.
 *
 * Responsibilities:
 * - Load `.env` (and the Symfony cascade `.env.local`, `.env.{APP_ENV}`,
 *   `.env.{APP_ENV}.local`) from the project root if present.
 * - Resolve the dev/prod mode once and delegate container construction
 *   to {@see ContainerFactory::create()} (framework defaults, convention
 *   configs, the caller's AppConfigurator, autowire-by-default, compile).
 * - Wrap the compiled container in a PSR-11 adapter for AppRouter and
 *   build the {@see UsePHP} runtime for PSX components.
 *
 * Returns the configured AppRouter so the caller decides when to `->run()`
 * and can still call setJsPath/addCssPath/etc. before running.
 */
final class Relayer
{
    /**
     * Project-root-relative directory the dev profiler persists profiles
     * into (one `{token}.json` per request, via
     * {@see FileProfilerStorage}). The single source of truth for that
     * location: the dev wiring in {@see ContainerFactory} binds
     * `FileProfilerStorage` to the absolute path `<projectRoot>/` followed
     * by this constant's value, and `relayer profiler:clear` resolves the
     * same path off this constant so the two cannot drift.
     */
    public const PROFILER_CACHE_DIR = 'var/cache/profiler';

    /**
     * Project-root-relative path of the compiled-routes artifact. The
     * single source of truth for that location: the prod wiring below
     * passes `<projectRoot>/` + this constant to {@see AppRouter} as the
     * presence-gated route source, and `relayer routes:compile` writes the
     * same path off this constant so the two cannot drift. Dev is never
     * pointed at it, so a live filesystem scan always wins there.
     */
    public const COMPILED_ROUTES_FILE = 'var/cache/routes/routes.php';

    /**
     * @param string               $projectRoot  Absolute path to the project root (the
     *                                           directory that contains composer.json, .env, and `src/Pages/`).
     * @param null|AppConfigurator $configurator Optional configurator.
     *                                           Defaults to a bare AppConfigurator with no extra services.
     */
    public static function boot(string $projectRoot, ?AppConfigurator $configurator = null): AppRouter
    {
        $projectRoot = \rtrim($projectRoot, '/');

        self::loadEnv($projectRoot);

        // Resolve dev/prod once, after .env is loaded, and thread it into
        // every downstream decision so the framework can't disagree with
        // itself mid-boot.
        $isDev = self::isDev();

        $container = ContainerFactory::create($projectRoot, $configurator, $isDev);
        $psr = new InjectorContainer($container);

        $appDir = $projectRoot . '/src/Pages';

        // Pin the page-PSX cache to <projectRoot>/var/cache/psx — the same
        // base buildUsePhp() passes to PsxComponentRegistrar::configure() for
        // the component manifest, and the same default
        // `vendor/bin/usephp compile` writes to. AppRouter's own default
        // derives this from dirname($appDir), which for the
        // standard `src/Pages` layout resolves one level short
        // (<root>/src/var/cache/psx), splitting the cache and defeating
        // precompilation. Passing it explicitly keeps both caches in one
        // place. See https://github.com/polidog/relayer/issues/21
        $psxCacheDir = $projectRoot . '/var/cache/psx';

        // Dev: swap in TraceableAppRouter so dispatch lifecycle events
        // land in the container-bound Profiler. Prod stays on the plain
        // AppRouter and the Traceable* class is never autoloaded.
        if ($isDev) {
            $traceable = new TraceableAppRouter($appDir, autoCompilePsx: true, psxCacheDir: $psxCacheDir);
            $extraExcludes = self::readEnvList('PROFILER_EXCLUDED_PATHS');
            if ([] !== $extraExcludes) {
                $traceable->setExcludedPrefixes($extraExcludes);
            }
            $router = $traceable;
        } else {
            // Prod: read the precompiled route artifact when it exists
            // (`relayer routes:compile` at deploy), otherwise fall back to
            // a live scan. Dev deliberately gets no path so it always
            // reflects the current tree.
            $router = AppRouter::create(
                $appDir,
                psxCacheDir: $psxCacheDir,
                compiledRoutesFile: $projectRoot . '/' . self::COMPILED_ROUTES_FILE,
            );
        }
        $router->setContainer($psr);

        $usephp = self::buildUsePhp($projectRoot, $isDev);
        $router->setUsePhp($usephp);

        return $router;
    }

    /**
     * Construct a {@see UsePHP} instance for PSX components + deferred dispatch.
     *
     * The snapshot secret HMAC-signs `StorageType::Snapshot` component state
     * so it survives a round-trip through the client without tampering. It is
     * NOT used by the defer endpoint (`/_defer/{name}` is a plain GET since
     * use-php 0.4.0). Resolution order:
     *  1. `USEPHP_SNAPSHOT_SECRET` env var (intended for prod — set a long
     *     random string).
     *  2. In dev only, fall back to a deterministic per-project secret so
     *     starters work out of the box without forcing every project to
     *     configure secrets first. Prod gets no fallback: with no secret the
     *     serializer is simply not configured, and use-php 0.5.0 fails loudly
     *     (LogicException) the moment a page actually serializes snapshot
     *     state — an unsigned client round-trip would be forgeable. Apps that
     *     never use Snapshot storage (e.g. the defer-only example) boot fine
     *     without one.
     *
     * Components in `src/Components/` (if present) are compiled into a
     * manifest at `var/cache/psx/manifest.php` (and a sibling
     * `deferred-manifest.php` for components carrying `#[Defer]` or
     * `fc(..., defer: ...)`). In dev the manifest is regenerated whenever a
     * `.psx` source is newer than the manifest; prod expects
     * `vendor/bin/usephp compile src/Components/` to have run during deploy.
     */
    private static function buildUsePhp(string $projectRoot, bool $isDev): UsePHP
    {
        $app = new UsePHP();

        $secret = self::resolveSnapshotSecret($projectRoot, $isDev);
        if ('' !== $secret) {
            $app->setSnapshotSecret($secret);
        }

        PsxComponentRegistrar::configure(
            $app,
            componentsDir: $projectRoot . '/src/Components',
            cacheDir: $projectRoot . '/var/cache/psx',
            autoCompile: $isDev,
        );

        return $app;
    }

    private static function resolveSnapshotSecret(string $projectRoot, bool $isDev): string
    {
        $explicit = $_ENV['USEPHP_SNAPSHOT_SECRET']
            ?? $_SERVER['USEPHP_SNAPSHOT_SECRET']
            ?? \getenv('USEPHP_SNAPSHOT_SECRET');

        // Trim before return — secrets sourced from files often pick up a
        // trailing newline. Without normalizing here, the HMAC would silently
        // diverge from the value an operator pasted into a .env file, and
        // every snapshot signature would fail verification with no obvious
        // cause.
        if (\is_string($explicit) && '' !== \trim($explicit)) {
            return \trim($explicit);
        }

        if (!$isDev) {
            // Prod: don't invent a secret. Without one, UsePHP leaves the
            // snapshot serializer unconfigured; use-php 0.5.0 then throws a
            // clear LogicException the moment a page serializes snapshot
            // state. Apps that use `StorageType::Snapshot` MUST set
            // USEPHP_SNAPSHOT_SECRET in production; this fallback's absence
            // exists so defer-only / non-snapshot apps still boot without one.
            return '';
        }

        // Dev fallback: stable per-project secret so snapshot-based demos
        // work immediately. The project root path is unique to the checkout,
        // so two devs on the same machine don't share a key by accident.
        return 'relayer-dev:' . \hash('sha256', $projectRoot);
    }

    /**
     * Read a comma-separated env var into a normalized list. Empty entries
     * are dropped. Returns `[]` when the var is unset or empty.
     *
     * @return list<string>
     */
    private static function readEnvList(string $name): array
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);
        if (!\is_string($raw) || '' === \trim($raw)) {
            return [];
        }

        $out = [];
        foreach (\explode(',', $raw) as $entry) {
            $entry = \trim($entry);
            if ('' !== $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Load env vars via Symfony Dotenv. `loadEnv()` walks the standard
     * cascade — `.env` → `.env.local` → `.env.{APP_ENV}` → `.env.{APP_ENV}.local`
     * — and skips files that are missing. Existing $_ENV / $_SERVER /
     * getenv() values win over `.env` (overrideExistingVars=false), while
     * the `.local` files override their committed counterparts as Symfony
     * convention prescribes.
     *
     * No `.env` at all → silently skip.
     */
    private static function loadEnv(string $projectRoot): void
    {
        if (!\file_exists($projectRoot . '/.env')) {
            return;
        }

        (new Dotenv())->loadEnv($projectRoot . '/.env');
    }

    private static function isDev(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? \getenv('APP_ENV') ?: 'prod';

        return 'dev' === $env || 'development' === $env;
    }
}
