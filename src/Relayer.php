<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use LogicException;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\I18n\Translators;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Routing\CompiledRoutes;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;
use Psr\Container\ContainerInterface;
use RuntimeException;
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
     * Project-root-relative path of the dumped DI container artifact.
     * Same presence-gated, single-source-of-truth contract as
     * {@see COMPILED_ROUTES_FILE}: prod boot below `require`s this file
     * and instantiates {@see COMPILED_CONTAINER_CLASS} when it exists
     * (skipping the full ContainerBuilder build + compile() that
     * {@see ContainerFactory::create()} runs per request), and `relayer
     * container:compile` writes the same path off this constant so the
     * two cannot drift. Dev never reads it, so a live container build
     * always wins there and config edits are picked up immediately.
     */
    public const COMPILED_CONTAINER_FILE = 'var/cache/container/CompiledContainer.php';

    /**
     * Fully-qualified class name `relayer container:compile` dumps into
     * {@see COMPILED_CONTAINER_FILE} and that prod boot instantiates.
     * Namespaced under a dedicated `Generated\` segment so the dumped
     * artifact can never collide with a hand-written framework class.
     */
    public const COMPILED_CONTAINER_CLASS = 'Polidog\Relayer\Generated\CompiledContainer';

    private static ?ContainerInterface $container = null;

    /**
     * Return the PSR-11 container built by the last {@see boot()} call.
     *
     * Intended as an escape hatch for PSX components, where constructor /
     * parameter injection is not available (components are plain closures).
     * Prefer constructor injection via {@see AppConfigurator} for regular
     * services; use this only inside `.psx` component files.
     *
     * @throws LogicException when called before {@see boot()}
     */
    public static function container(): ContainerInterface
    {
        if (null === self::$container) {
            throw new LogicException('Relayer::container() called before Relayer::boot().');
        }

        return self::$container;
    }

    /**
     * Drop every piece of request-scoped state the framework (and usePHP)
     * keeps in statics, so the next request on a long-running worker
     * starts clean.
     *
     * Only needed under a worker loop — a classic per-request SAPI throws
     * the whole process away instead. {@see AppRouter::run()} already
     * clears its own ambient state in a `finally`; this adds the usePHP
     * runtime caches (component instances and their storage backends),
     * which would otherwise leak one visitor's component state into the
     * next visitor's render. Idempotent, so calling it after a request
     * that already cleaned up is free.
     *
     * The container built by {@see boot()} is deliberately NOT reset: it
     * is boot-scoped, and rebuilding it per request is exactly what worker
     * mode exists to avoid.
     */
    public static function endRequest(): void
    {
        ComponentState::clearInstances();
        StorageFactory::reset();
        RenderContext::clearApp();
        PageContext::setCurrent(null);
        Translators::reset();
    }

    /**
     * @param string               $projectRoot  Absolute path to the project root (the
     *                                           directory that contains composer.json, .env, and `src/Pages/`).
     * @param null|AppConfigurator $configurator Optional configurator.
     *                                           Defaults to a bare AppConfigurator with no extra services.
     *
     *                                           Contract: in prod, a dumped container
     *                                           ({@see COMPILED_CONTAINER_FILE}, written by
     *                                           `relayer container:compile`) is authoritative and
     *                                           this argument is NOT applied — the dump was baked
     *                                           from whatever `container:compile` discovered (the
     *                                           `App\AppConfigurator` convention, or none). The
     *                                           scaffolded `public/index.php` passes `new
     *                                           App\AppConfigurator($root)`, which matches. An
     *                                           entry point that builds a custom/parameterized
     *                                           configurator (e.g. extra constructor args) must
     *                                           keep `App\AppConfigurator` parity with what
     *                                           `container:compile` builds, or not precompile the
     *                                           container (delete the dump ⇒ live build applies
     *                                           this argument again). Dev always live-builds and
     *                                           always honors it.
     */
    public static function boot(string $projectRoot, ?AppConfigurator $configurator = null): AppRouter
    {
        $projectRoot = \rtrim($projectRoot, '/');

        self::loadEnv($projectRoot);

        // Resolve dev/prod once, after .env is loaded, and thread it into
        // every downstream decision so the framework can't disagree with
        // itself mid-boot.
        $isDev = self::isDev();

        // Same for the opt-in warm-up mode (RELAYER_WARM_CACHE): in prod,
        // build the missing build-time artifacts at runtime instead of
        // failing / live-building them per request. Meaningless in dev
        // (which always live-builds), so it is folded into `!$isDev`.
        $warm = !$isDev && self::isWarmEnabled();

        // ContainerFactory owns the "load the dump if present, else live
        // build" decision (mirrors AppRouter::create's presence-gated
        // artifact contract). Dev passes null so config edits never read
        // a stale dump; prod points at COMPILED_CONTAINER_FILE.
        $container = ContainerFactory::create(
            $projectRoot,
            $configurator,
            $isDev,
            $isDev ? null : $projectRoot . '/' . self::COMPILED_CONTAINER_FILE,
            warm: $warm,
        );

        $psr = new InjectorContainer($container);
        self::$container = $psr;

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

        $compiledRoutesFile = $isDev ? null : $projectRoot . '/' . self::COMPILED_ROUTES_FILE;

        if ($warm && null !== $compiledRoutesFile && !\is_file($compiledRoutesFile)) {
            self::warmRoutes($appDir, $compiledRoutesFile);
        }

        // Single router class for dev and prod. Profiling (dev only) is
        // wired in directly below — in prod neither `Profiler` nor
        // `ProfilerStorage` is bound on AppRouter, so every profiler
        // branch collapses to a null check. Prod points at the
        // precompiled route artifact when it exists; dev passes null so
        // config edits never read a stale dump.
        $router = AppRouter::create(
            $appDir,
            autoCompilePsx: $isDev || $warm,
            psxCacheDir: $psxCacheDir,
            compiledRoutesFile: $compiledRoutesFile,
        );
        $router->setContainer($psr);

        $usephp = self::buildUsePhp($projectRoot, $isDev, $warm, $psr);
        $router->setUsePhp($usephp);

        // Wire dev-time profiling when the container exposes a Profiler.
        // Prod's container binds NullProfiler (a no-op) — we deliberately
        // do NOT wire that into AppRouter so the dispatch hot path skips
        // the virtual call entirely. Storage is optional and only
        // surfaces the `/_profiler` viewer; the actual event recording
        // works without it.
        $profiler = self::resolveProfiler($psr);
        if (null !== $profiler) {
            $router->setProfiler($profiler, self::resolveProfilerStorage($psr));

            $extraExcludes = self::readEnvList('PROFILER_EXCLUDED_PATHS');
            if ([] !== $extraExcludes) {
                $router->setProfilerExcludedPrefixes($extraExcludes);
            }
        }

        return $router;
    }

    /**
     * Resolve the dev {@see Profiler} the container exposes, or null
     * when only the prod no-op is bound. Skipping `NullProfiler` here
     * keeps the dispatch hot path free of virtual calls in prod.
     */
    private static function resolveProfiler(InjectorContainer $psr): ?Profiler
    {
        if (!$psr->has(Profiler::class)) {
            return null;
        }

        $profiler = $psr->get(Profiler::class);
        if (!$profiler instanceof Profiler) {
            return null;
        }

        // isEnabled() differentiates RecordingProfiler (true) from
        // NullProfiler (false). The router's profiler hooks short-circuit
        // on null anyway, but skipping the wire-up entirely keeps the
        // intent explicit and avoids any per-hook overhead in prod.
        return $profiler->isEnabled() ? $profiler : null;
    }

    /**
     * Resolve the dev {@see ProfilerStorage} when bound — powers the
     * `/_profiler` viewer. Returns null in prod (only dev binds it).
     */
    private static function resolveProfilerStorage(InjectorContainer $psr): ?ProfilerStorage
    {
        if (!$psr->has(ProfilerStorage::class)) {
            return null;
        }

        $storage = $psr->get(ProfilerStorage::class);

        return $storage instanceof ProfilerStorage ? $storage : null;
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
    private static function buildUsePhp(
        string $projectRoot,
        bool $isDev,
        bool $warm,
        ?ContainerInterface $container = null,
    ): UsePHP {
        $app = new UsePHP();

        $secret = self::resolveSnapshotSecret($projectRoot, $isDev);
        if ('' !== $secret) {
            $app->setSnapshotSecret($secret);
        }

        PsxComponentRegistrar::configure(
            $app,
            componentsDir: $projectRoot . '/src/Components',
            cacheDir: $projectRoot . '/var/cache/psx',
            // Warm-up compiles the manifest at runtime too: without it a
            // prod boot with no precompiled manifest registers nothing and
            // deferred components silently stop working.
            autoCompile: $isDev || $warm,
            container: $container,
        );

        return $app;
    }

    /**
     * Warm-up counterpart of `relayer routes:compile`: scan `src/Pages/`
     * once and write the route artifact, so the *next* request (and every
     * request after it) loads the compiled file instead of rescanning.
     *
     * Best-effort by design. A scan failure (ambiguous routes, missing
     * directory) is left for {@see AppRouter} to report on the live path
     * it falls back to — reporting it here would duplicate the message and
     * make warm-up look like the cause. A write failure (read-only
     * filesystem) is likewise not fatal.
     */
    private static function warmRoutes(string $appDir, string $outFile): void
    {
        try {
            $routes = (new PageScanner($appDir))->scan();
        } catch (RuntimeException) {
            return;
        }

        CompiledRoutes::write($routes, $appDir, $outFile);
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

    /**
     * `RELAYER_WARM_CACHE` — opt in to building the prod artifacts
     * (compiled routes, dumped container, compiled `.psx`) at runtime,
     * into `var/cache/`, when a deploy step did not produce them.
     *
     * The case this exists for: a **FrankenPHP single binary**, which
     * extracts its embedded app into a fresh directory on start, so the
     * paths the build precompiled against no longer exist (the `.psx`
     * cache is keyed by the source's absolute path, and a dumped container
     * bakes absolute paths). Warming at runtime sidesteps that entirely —
     * the first request pays for it, everything after reads the same
     * artifacts an ordinary deploy would have shipped.
     *
     * Off by default: without it, prod keeps the presence-gated contract
     * (missing artifact ⇒ live path, missing compiled `.psx` ⇒ a loud
     * error pointing at `usephp compile`), which is what you want when the
     * deploy *should* have precompiled and silently recompiling would mask
     * a broken build.
     */
    private static function isWarmEnabled(): bool
    {
        $raw = $_ENV['RELAYER_WARM_CACHE'] ?? $_SERVER['RELAYER_WARM_CACHE'] ?? \getenv('RELAYER_WARM_CACHE');

        if (!\is_string($raw)) {
            return false;
        }

        return \in_array(\strtolower(\trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
