<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\NativeSession;
use Polidog\Relayer\Auth\PasswordHasher;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Auth\TraceableAuthenticator;
use Polidog\Relayer\Auth\TraceableSessionStorage;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Db\CachingDatabase;
use Polidog\Relayer\Db\Database;
use Polidog\Relayer\Db\PdoDatabase;
use Polidog\Relayer\Db\TraceableDatabase;
use Polidog\Relayer\Http\Client\CachingHttpClient;
use Polidog\Relayer\Http\Client\CurlHttpClient;
use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Http\Client\TraceableHttpClient;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\FileEtagStore;
use Polidog\Relayer\Http\TraceableEtagStore;
use Polidog\Relayer\Log\TraceableLogger;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\TraceableAppRouter;
use Polidog\UsePhp\UsePHP;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Dotenv\Dotenv;

/**
 * One-shot bootstrapper for usePHP applications.
 *
 * Responsibilities:
 * - Load `.env` (and the Symfony cascade `.env.local`, `.env.{APP_ENV}`,
 *   `.env.{APP_ENV}.local`) from the project root if present.
 * - Build a Symfony DI ContainerBuilder with autowire-by-default semantics.
 * - Apply the caller-supplied AppConfigurator (or a bare default) for custom bindings.
 * - Compile the container and wrap it in a PSR-11 adapter for AppRouter.
 *
 * Returns the configured AppRouter so the caller decides when to `->run()`
 * and can still call setJsPath/addCssPath/etc. before running.
 */
final class Relayer
{
    /**
     * Project-root-relative directory the dev profiler persists profiles
     * into (one `{token}.json` per request, via {@see FileProfilerStorage}).
     * The single source of truth for that location: the dev wiring below
     * binds `FileProfilerStorage` to `<projectRoot>/` . this, and
     * `relayer profiler:clear` clears the same path off this constant so
     * the two cannot drift.
     */
    public const PROFILER_CACHE_DIR = 'var/cache/profiler';

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

        $container = self::buildContainer($projectRoot, $configurator);
        $psr = new InjectorContainer($container);

        $appDir = $projectRoot . '/src/Pages';
        $isDev = self::isDev();

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
            $router = AppRouter::create($appDir, psxCacheDir: $psxCacheDir);
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
     * Read a single env var as a trimmed string. Returns `''` when unset
     * or blank so callers can use `?:` to fall back to null.
     */
    private static function readEnv(string $name): string
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);

        return \is_string($raw) ? \trim($raw) : '';
    }

    /**
     * Read a single env var as a non-negative int (`0` included), or null
     * when unset/blank or not all-digits. Used for the DB timeout knobs;
     * `0` is passed straight to PDO, where it carries the driver's own
     * "no timeout" meaning.
     */
    private static function readEnvInt(string $name): ?int
    {
        $raw = self::readEnv($name);

        return \ctype_digit($raw) ? (int) $raw : null;
    }

    private static function buildContainer(string $projectRoot, ?AppConfigurator $configurator): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.project_root', $projectRoot);

        self::registerDefaults($container, $projectRoot);
        self::loadConventionConfigs($container, $projectRoot);

        $configurator ??= new AppConfigurator($projectRoot);
        $configurator->configure($container);

        // Conditionally register Authenticator now that the app has had
        // a chance to bind a UserProvider. Without this gate, apps that
        // don't use auth would fail container compilation because
        // Authenticator's `$users` parameter is unsatisfiable.
        if ($container->has(UserProvider::class) && !$container->has(Authenticator::class)) {
            $container->register(Authenticator::class)
                ->setAutowired(true)
                ->setPublic(true)
            ;
        }

        // Bind the AuthenticatorInterface ID to the concrete Authenticator
        // when auth is configured. In dev, swap the alias to point at the
        // TraceableAuthenticator decorator so framework code (and apps
        // that depend on the interface) get auth event tracing for free.
        if ($container->has(Authenticator::class)) {
            $container->setAlias(AuthenticatorInterface::class, Authenticator::class)
                ->setPublic(true)
            ;

            if (self::isDev()) {
                $container->register(TraceableAuthenticator::class)
                    ->setArguments([
                        new Reference(Authenticator::class),
                        new Reference(Profiler::class),
                    ])
                    ->setPublic(true)
                ;
                $container->setAlias(AuthenticatorInterface::class, TraceableAuthenticator::class)
                    ->setPublic(true)
                ;
            }
        }

        // Autowire-by-default: any service registered without explicit
        // arguments gets autowiring + public visibility so it can be fetched
        // via PSR-11 get($id). YAML/_defaults values win because we only fill
        // in when nothing was specified.
        foreach ($container->getDefinitions() as $definition) {
            self::applyDefaults($definition);
        }

        $container->compile();

        return $container;
    }

    /**
     * Register framework-provided defaults that users may override. These
     * land in the container BEFORE convention configs and the user's
     * AppConfigurator, so anything registered later wins.
     */
    private static function registerDefaults(ContainerBuilder $container, string $projectRoot): void
    {
        $container->register(FileEtagStore::class)
            ->setArguments([$projectRoot . '/var/cache/etags'])
            ->setPublic(true)
        ;

        $container->setAlias(EtagStore::class, FileEtagStore::class)
            ->setPublic(true)
        ;

        // Auth defaults. The Authenticator is only useful when the app
        // also registers a UserProvider, but we always wire the hasher
        // and session adapter so apps can take partial dependencies
        // (e.g. just the PasswordHasher during signup before login is
        // wired) without extra ceremony.
        $container->register(NativePasswordHasher::class)
            ->setPublic(true)
        ;
        $container->setAlias(PasswordHasher::class, NativePasswordHasher::class)
            ->setPublic(true)
        ;

        $container->register(NativeSession::class)
            ->setPublic(true)
        ;
        $container->setAlias(SessionStorage::class, NativeSession::class)
            ->setPublic(true)
        ;

        // Authenticator is NOT registered unconditionally — it depends on
        // UserProvider, which the app supplies. We register it in a
        // deferred step in buildContainer() only when UserProvider has
        // been bound by the user's AppConfigurator. Apps without auth
        // pay nothing.

        // Profiler. Prod resolves to NullProfiler so user code can take a
        // `Profiler` dependency without any cost; dev swaps the alias to
        // RecordingProfiler so events land on disk via FileProfilerStorage.
        $container->register(NullProfiler::class)
            ->setPublic(true)
        ;
        $container->setAlias(Profiler::class, NullProfiler::class)
            ->setPublic(true)
        ;

        if (self::isDev()) {
            $container->register(FileProfilerStorage::class)
                ->setArguments([$projectRoot . '/' . self::PROFILER_CACHE_DIR])
                ->setPublic(true)
            ;
            $container->setAlias(ProfilerStorage::class, FileProfilerStorage::class)
                ->setPublic(true)
            ;

            $container->register(RecordingProfiler::class)
                ->setAutowired(true)
                ->setPublic(true)
            ;
            $container->setAlias(Profiler::class, RecordingProfiler::class)
                ->setPublic(true)
            ;

            // Dev-only: swap EtagStore + SessionStorage aliases to point at
            // the traceable decorators so cache.etag_* and session.* events
            // land in the profile alongside the rest of the request timeline.
            $container->register(TraceableEtagStore::class)
                ->setArguments([
                    new Reference(FileEtagStore::class),
                    new Reference(Profiler::class),
                ])
                ->setPublic(true)
            ;
            $container->setAlias(EtagStore::class, TraceableEtagStore::class)
                ->setPublic(true)
            ;

            $container->register(TraceableSessionStorage::class)
                ->setArguments([
                    new Reference(NativeSession::class),
                    new Reference(Profiler::class),
                ])
                ->setPublic(true)
            ;
            $container->setAlias(SessionStorage::class, TraceableSessionStorage::class)
                ->setPublic(true)
            ;
        }

        // Database. Registered only when DATABASE_DSN is set, mirroring
        // the conditional Authenticator wiring — apps without a database
        // pay nothing and never fail container compilation over an
        // unsatisfiable PdoDatabase. The Database alias always resolves
        // to CachingDatabase (request-scoped read memoization); in dev it
        // wraps TraceableDatabase so queries land in the profiler, in
        // prod it wraps PdoDatabase directly.
        $dsn = self::readEnv('DATABASE_DSN');
        if ('' !== $dsn) {
            $container->register(PdoDatabase::class)
                ->setArguments([
                    $dsn,
                    self::readEnv('DATABASE_USER') ?: null,
                    self::readEnv('DATABASE_PASSWORD') ?: null,
                    self::readEnvInt('DATABASE_TIMEOUT'),
                    self::readEnvInt('DATABASE_READ_TIMEOUT'),
                ])
                ->setPublic(true)
            ;

            $cacheInner = new Reference(PdoDatabase::class);

            if (self::isDev()) {
                $container->register(TraceableDatabase::class)
                    ->setArguments([
                        new Reference(PdoDatabase::class),
                        new Reference(Profiler::class),
                    ])
                    ->setPublic(true)
                ;
                $cacheInner = new Reference(TraceableDatabase::class);
            }

            $container->register(CachingDatabase::class)
                ->setArguments([
                    $cacheInner,
                    new Reference(Profiler::class),
                ])
                ->setPublic(true)
            ;
            $container->setAlias(Database::class, CachingDatabase::class)
                ->setPublic(true)
            ;
        }

        // HTTP client. Always registered — unlike the DB, an outbound HTTP
        // client needs no required config, so (like the EtagStore) any
        // page/component can take an HttpClient dependency with zero setup.
        // The HttpClient alias always resolves to CachingHttpClient
        // (request-scoped memoization of safe requests); in dev it wraps
        // TraceableHttpClient so real round-trips land in the profiler, in
        // prod it wraps CurlHttpClient directly. Mirrors the Database stack.
        $container->register(CurlHttpClient::class)
            ->setArguments([
                self::readEnvInt('HTTP_CLIENT_TIMEOUT'),
                self::readEnvInt('HTTP_CLIENT_CONNECT_TIMEOUT'),
            ])
            ->setPublic(true)
        ;

        $httpCacheInner = new Reference(CurlHttpClient::class);

        if (self::isDev()) {
            $container->register(TraceableHttpClient::class)
                ->setArguments([
                    new Reference(CurlHttpClient::class),
                    new Reference(Profiler::class),
                ])
                ->setPublic(true)
            ;
            $httpCacheInner = new Reference(TraceableHttpClient::class);
        }

        $container->register(CachingHttpClient::class)
            ->setArguments([
                $httpCacheInner,
                new Reference(Profiler::class),
            ])
            ->setPublic(true)
        ;
        $container->setAlias(HttpClient::class, CachingHttpClient::class)
            ->setPublic(true)
        ;

        // Logger. Always registered — like the HTTP client it needs no
        // required config, so any page/component can inject
        // `Psr\Log\LoggerInterface` with zero setup. The implementation is
        // Monolog (which also satisfies the psr/log contract apps and
        // third-party libs share). Sink defaults to STDERR (12-factor:
        // docker logs / journald / a platform drain collect it); set
        // LOG_FILE to redirect to a path for deploys that want a file.
        // LOG_LEVEL overrides the threshold (default dev=debug, prod=info).
        // PsrLogMessageProcessor gives the sink PSR-3 `{placeholder}`
        // interpolation, which Monolog does not do on its own.
        $logFile = self::readEnv('LOG_FILE');
        $logStream = '' !== $logFile ? $logFile : 'php://stderr';
        $logLevel = self::readLogLevel(self::isDev() ? LogLevel::DEBUG : LogLevel::INFO);

        $container->register(StreamHandler::class)
            ->setArguments([$logStream, $logLevel])
            ->setPublic(true)
        ;
        $container->register(PsrLogMessageProcessor::class)
            ->setPublic(true)
        ;
        $container->register(Logger::class)
            ->setArguments([
                'app',
                [new Reference(StreamHandler::class)],
                [new Reference(PsrLogMessageProcessor::class)],
            ])
            ->setPublic(true)
        ;
        $container->setAlias(LoggerInterface::class, Logger::class)
            ->setPublic(true)
        ;

        if (self::isDev()) {
            $container->register(TraceableLogger::class)
                ->setArguments([
                    new Reference(Logger::class),
                    new Reference(Profiler::class),
                ])
                ->setPublic(true)
            ;
            $container->setAlias(LoggerInterface::class, TraceableLogger::class)
                ->setPublic(true)
            ;
        }
    }

    /**
     * Resolve the log threshold from `LOG_LEVEL`, falling back to
     * `$default` when unset or not one of the eight PSR-3 level names.
     * Soft-fails like {@see readEnvInt()} rather than letting Monolog
     * throw on a typo'd level.
     */
    private static function readLogLevel(string $default): string
    {
        $raw = \strtolower(self::readEnv('LOG_LEVEL'));

        $valid = [
            LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
            LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY,
        ];

        return \in_array($raw, $valid, true) ? $raw : $default;
    }

    /**
     * Auto-load `config/services.{yaml,yml,php}` if present. Symfony's loaders
     * honor `_defaults: { autowire: true, public: true }` blocks naturally,
     * so users get full Symfony semantics; the AppConfigurator runs after
     * these files and can override anything they registered.
     */
    private static function loadConventionConfigs(ContainerBuilder $container, string $projectRoot): void
    {
        $configDir = $projectRoot . '/config';
        if (!\is_dir($configDir)) {
            return;
        }

        $locator = new FileLocator($configDir);

        foreach (['services.yaml', 'services.yml'] as $name) {
            if (\file_exists($configDir . '/' . $name)) {
                (new YamlFileLoader($container, $locator))->load($name);

                break;
            }
        }

        if (\file_exists($configDir . '/services.php')) {
            (new PhpFileLoader($container, $locator))->load('services.php');
        }
    }

    private static function applyDefaults(Definition $definition): void
    {
        if (!$definition->isAutowired() && [] === $definition->getArguments()) {
            $definition->setAutowired(true);
        }
        if (!$definition->isPublic()) {
            $definition->setPublic(true);
        }
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
