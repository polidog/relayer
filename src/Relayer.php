<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Dispatch\DispatchListener;
use Polidog\Relayer\Router\Dispatch\ProfilingListener;
use Polidog\Relayer\Router\Dispatch\RuntimeDispatcher;
use Polidog\UsePhp\UsePHP;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
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

    /**
     * Symfony service tag the DI container marks
     * {@see DispatchListener} services with.
     * {@see Relayer::boot()} reads the resolved list (via
     * {@see DISPATCH_LISTENERS_PARAMETER}) at runtime, so the dispatch
     * chain is discovered the same way in dev and prod. `relayer
     * dispatch:list` prints that chain so an operator can audit it
     * without running the app.
     */
    public const DISPATCH_LISTENER_TAG = 'relayer.dispatch_listener';

    /**
     * Container parameter that holds the resolved list of
     * `relayer.dispatch_listener` service IDs (class-strings), in
     * registration order. Set by {@see ContainerFactory} just before
     * `compile()` so the value survives a {@see PhpDumper} round-trip —
     * dumped containers expose `getParameter()` but lose the tag index,
     * so a plain `findTaggedServiceIds()` call would not work at runtime
     * under the compiled container. The parameter is the
     * runtime-portable mirror of that lookup.
     */
    public const DISPATCH_LISTENERS_PARAMETER = 'relayer.dispatch_listeners';

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

        // ContainerFactory owns the "load the dump if present, else live
        // build" decision (mirrors AppRouter::create's presence-gated
        // artifact contract). Dev passes null so config edits never read
        // a stale dump; prod points at COMPILED_CONTAINER_FILE.
        $container = ContainerFactory::create(
            $projectRoot,
            $configurator,
            $isDev,
            $isDev ? null : $projectRoot . '/' . self::COMPILED_CONTAINER_FILE,
        );

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

        // Single router class for dev and prod — the recording / framework
        // behavior the previous TraceableAppRouter subclass carried now
        // lives in {@see ProfilingListener}, attached below. Prod points
        // at the precompiled route artifact when it exists; dev passes
        // null so config edits never read a stale dump.
        $router = AppRouter::create(
            $appDir,
            autoCompilePsx: $isDev,
            psxCacheDir: $psxCacheDir,
            compiledRoutesFile: $isDev ? null : $projectRoot . '/' . self::COMPILED_ROUTES_FILE,
        );
        $router->setContainer($psr);

        $usephp = self::buildUsePhp($projectRoot, $isDev);
        $router->setUsePhp($usephp);

        // Attach the dispatch listener — a polymorphic RuntimeDispatcher
        // over the tag-discovered listeners (singleton bypass when the
        // chain has exactly one listener, the typical case). `relayer
        // dispatch:list` prints the same chain for offline audit.
        $listener = self::resolveListener($container, $psr);
        if (null !== $listener) {
            // Apps configure extra profile-excluded paths via
            // PROFILER_EXCLUDED_PATHS env — only the framework's own
            // ProfilingListener knows what to do with the list, so look
            // it up by service id on the underlying container (the PSR
            // wrapper exposes the same `has`/`get` semantics).
            self::applyProfilerExcludedPrefixes($psr);

            $router->setListener($listener);
        }

        return $router;
    }

    /**
     * Resolve the {@see DispatchListener} to install on the router as a
     * {@see RuntimeDispatcher} over the {@see DISPATCH_LISTENERS_PARAMETER}
     * service IDs. Returns null only when the container exposes no
     * listeners at all — e.g. an app explicitly overrode the framework's
     * ProfilingListener registration to untag it; in that case the
     * router runs against its default {@see NullDispatchListener}.
     */
    private static function resolveListener(
        SymfonyContainerInterface $container,
        InjectorContainer $psr,
    ): ?DispatchListener {
        $listeners = self::discoverListeners($container, $psr);
        if ([] === $listeners) {
            return null;
        }
        if (1 === \count($listeners)) {
            // Pass the singleton through directly so the router's
            // setListener can dispatch hooks without the polymorphic
            // foreach fan-out.
            return $listeners[0];
        }

        return new RuntimeDispatcher($listeners);
    }

    /**
     * Read the tag-resolved listener service IDs off the container
     * parameter {@see ContainerFactory} stashed before compile, fetch
     * each one through the PSR adapter, and assert each implements
     * {@see DispatchListener}.
     *
     * @return list<DispatchListener>
     */
    private static function discoverListeners(SymfonyContainerInterface $container, InjectorContainer $psr): array
    {
        if (!$container->hasParameter(self::DISPATCH_LISTENERS_PARAMETER)) {
            return [];
        }
        $ids = $container->getParameter(self::DISPATCH_LISTENERS_PARAMETER);
        if (!\is_array($ids)) {
            return [];
        }

        $listeners = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !$psr->has($id)) {
                continue;
            }
            $service = $psr->get($id);
            if ($service instanceof DispatchListener) {
                $listeners[] = $service;
            }
        }

        return $listeners;
    }

    /**
     * Apply the `PROFILER_EXCLUDED_PATHS` env list to the framework's
     * own {@see ProfilingListener} when the container exposes one. No-op
     * when the listener was overridden away (apps can disable framework
     * profiling without disabling the env handling for other listeners).
     */
    private static function applyProfilerExcludedPrefixes(InjectorContainer $psr): void
    {
        $extraExcludes = self::readEnvList('PROFILER_EXCLUDED_PATHS');
        if ([] === $extraExcludes || !$psr->has(ProfilingListener::class)) {
            return;
        }

        $profiling = $psr->get(ProfilingListener::class);
        if ($profiling instanceof ProfilingListener) {
            $profiling->setExcludedPrefixes($extraExcludes);
        }
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
