<?php

declare(strict_types=1);

namespace Polidog\Relayer\Di;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\NativeSession;
use Polidog\Relayer\Auth\PasswordHasher;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Auth\Token\AuthorizationHeader;
use Polidog\Relayer\Auth\Token\ServerAuthorizationHeader;
use Polidog\Relayer\Auth\Token\TokenAuthenticator;
use Polidog\Relayer\Auth\Token\TokenVerifier;
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
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\Log\TraceableLogger;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Relayer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds and compiles the application's Symfony DI container.
 *
 * Extracted from {@see Relayer} so the bootstrapper stays a thin
 * orchestrator: this class owns *what services exist* (framework
 * defaults, the dev-only `Traceable*` decorators, the conditional
 * Auth/Database wiring, convention config loading, autowire-by-default)
 * while {@see Relayer::boot()} owns *the boot sequence* (env, router,
 * usePHP). It reads no global boot state — the dev/prod decision is
 * resolved once by `boot()` and threaded in as `$isDev`.
 *
 * Layering, lowest-precedence first (later wins): framework defaults →
 * `config/services.{yaml,php}` → the caller's AppConfigurator.
 */
final class ContainerFactory
{
    /**
     * Build the container — or, when a precompiled dump is supplied and
     * present, load it without rebuilding.
     *
     * The "build vs. load" decision lives here so {@see Relayer::boot()}
     * stays a thin orchestrator. Mirrors {@see
     * \Polidog\Relayer\Router\AppRouter::create()}'s presence-gated
     * artifact contract: the caller decides *whether* to point the
     * factory at a compiled file; the factory decides whether to *use*
     * it (file exists ⇒ require + instantiate + guard; otherwise live
     * build). Dev callers pass null so config edits never go through a
     * stale dump; prod passes the {@see Relayer::COMPILED_CONTAINER_FILE}
     * absolute path. When a dump is loaded, `$configurator` is ignored —
     * the dump's wiring is authoritative (see {@see Relayer::boot()}'s
     * contract docblock).
     *
     * @param string               $projectRoot           Absolute project root
     * @param null|AppConfigurator $configurator          Caller bindings; a bare
     *                                                    default when null. Ignored on
     *                                                    the load path.
     * @param bool                 $isDev                 Resolved once by
     *                                                    {@see Relayer::boot()}
     * @param null|string          $compiledContainerFile Absolute path to a PhpDumper
     *                                                    artifact, or null to always
     *                                                    live-build
     *
     * @return ($compiledContainerFile is null ? ContainerBuilder : ContainerInterface)
     */
    public static function create(
        string $projectRoot,
        ?AppConfigurator $configurator,
        bool $isDev,
        ?string $compiledContainerFile = null,
    ): ContainerInterface {
        if (null !== $compiledContainerFile && \is_file($compiledContainerFile)) {
            require_once $compiledContainerFile;

            // The dumped class only exists after `container:compile`
            // generated it, so it is opaque to static analysis — treat
            // the FQCN as a plain string and prove the contract at
            // runtime via the instanceof guard below.
            /** @var string $dumpedClass */
            $dumpedClass = Relayer::COMPILED_CONTAINER_CLASS;
            $container = new $dumpedClass();

            if (!$container instanceof ContainerInterface) {
                throw new RuntimeException(\sprintf(
                    'Dumped container %s did not produce a %s — re-run `relayer container:compile`.',
                    $compiledContainerFile,
                    ContainerInterface::class,
                ));
            }

            return $container;
        }

        $container = new ContainerBuilder();
        $container->setParameter('app.project_root', $projectRoot);

        self::registerDefaults($container, $projectRoot, $isDev);
        self::loadConventionConfigs($container, $projectRoot);

        $configurator ??= new AppConfigurator($projectRoot);
        $configurator->configure($container);

        // Conditionally register the session Authenticator now that the
        // app has had a chance to bind a UserProvider and/or a
        // TokenVerifier. It no longer needs either to function for
        // login()/logout()/user()/check() (only the always-bound
        // SessionStorage), so a token-first app still gets a working
        // session authenticator for the verify-then-login mode. With no
        // auth configured at all, nothing is registered and apps that
        // don't use auth pay nothing and never fail compilation over an
        // unsatisfiable dependency.
        $hasUsers = $container->has(UserProvider::class);
        $hasTokenVerifier = $container->has(TokenVerifier::class);

        if (($hasUsers || $hasTokenVerifier) && !$container->has(Authenticator::class)) {
            $container->register(Authenticator::class)
                ->setAutowired(true)
                ->setPublic(true)
            ;
        }

        // Stateless bearer authenticator. Registered whenever a
        // TokenVerifier is bound, so an app can type-hint it directly on
        // specific API routes even when a password UserProvider also
        // exists. Gated on TokenVerifier so its dependency stays
        // satisfiable (mirrors the Authenticator gate).
        if ($hasTokenVerifier && !$container->has(TokenAuthenticator::class)) {
            $container->register(TokenAuthenticator::class)
                ->setAutowired(true)
                ->setPublic(true)
            ;
        }

        // Pick the AuthenticatorInterface implementation that #[Auth]
        // enforces. One rule, no hybrid: a password UserProvider means a
        // session-first app, so the guard runs against the session
        // Authenticator; otherwise a TokenVerifier means a token-first
        // app, so the guard runs against the stateless TokenAuthenticator
        // (and `#[Auth(redirectTo: '')]` yields a clean 401). In dev,
        // whichever is chosen is wrapped by TraceableAuthenticator, which
        // decorates the interface so it fits either concrete.
        $authConcrete = null;
        if ($hasUsers && $container->has(Authenticator::class)) {
            $authConcrete = Authenticator::class;
        } elseif ($hasTokenVerifier) {
            $authConcrete = TokenAuthenticator::class;
        }

        if (null !== $authConcrete) {
            $container->setAlias(AuthenticatorInterface::class, $authConcrete)
                ->setPublic(true)
            ;

            if ($isDev) {
                $container->register(TraceableAuthenticator::class)
                    ->setArguments([
                        new Reference($authConcrete),
                        new Reference(Profiler::class),
                    ])
                    ->setPublic(true)
                ;
                $container->setAlias(AuthenticatorInterface::class, TraceableAuthenticator::class)
                    ->setPublic(true)
                ;
            }
        }

        // Autowire-by-default normalization (see applyDefaults). Two
        // different precedence rules, deliberately:
        //  - Autowiring is only turned on when the user specified neither
        //    autowiring nor explicit arguments, so an explicit YAML
        //    definition keeps its own wiring.
        //  - Public visibility is ALWAYS forced on, even over an explicit
        //    `public: false`: Relayer resolves pages/services through the
        //    container by id (PSR-11 get($id) via InjectorContainer), so a
        //    private service would simply be unfetchable. Everything-public
        //    is a framework invariant here, not a fillable default.
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
    private static function registerDefaults(ContainerBuilder $container, string $projectRoot, bool $isDev): void
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

        // Always registered (no required config, like the HTTP client):
        // it only reads $_SERVER, so the stateless TokenAuthenticator can
        // be autowired with zero app setup once a TokenVerifier is bound.
        $container->register(ServerAuthorizationHeader::class)
            ->setPublic(true)
        ;
        $container->setAlias(AuthorizationHeader::class, ServerAuthorizationHeader::class)
            ->setPublic(true)
        ;

        // Authenticator is NOT registered here. It no longer depends on
        // UserProvider (both $users and $hasher are optional), but
        // registering it only makes sense once auth is actually
        // configured. create() does that in a deferred step, gated on
        // "a UserProvider OR a TokenVerifier is bound". Apps without auth
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

        if ($isDev) {
            $container->register(FileProfilerStorage::class)
                ->setArguments([$projectRoot . '/' . Relayer::PROFILER_CACHE_DIR])
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

            if ($isDev) {
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

        if ($isDev) {
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
        $logLevel = self::readLogLevel($isDev ? LogLevel::DEBUG : LogLevel::INFO);

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

        if ($isDev) {
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

        self::registerI18n($container, $projectRoot);
    }

    /**
     * i18n defaults. Always registered (like the HTTP client / logger):
     * both services are cheap, and a single-locale app simply resolves to
     * its one locale with the framework's English catalog — so an app that
     * never configures i18n keeps the pre-i18n output at no cost.
     *
     * Crucially, `/{locale}/...` path-prefix routing is only switched on
     * when 2+ locales are actually configured. With no `APP_LOCALES` (or a
     * single locale) there is no locale to disambiguate, so a request to
     * `/en/...` is left exactly as-is — a previously-valid route under
     * `/en/*` keeps working, which is what "pre-i18n behavior at no cost"
     * has to mean. `LOCALE_PATH_PREFIX` only *opts out* of the otherwise-on
     * prefix routing in a multi-locale app; it cannot force prefix routing
     * onto a single-locale one (there would be nothing to route).
     *
     * Knobs (all optional, read from the `.env` cascade):
     *  - `APP_LOCALE`         default/active locale (default `en`); English
     *                         is always the built-in fallback for the
     *                         framework's own `relayer.*` messages
     *  - `APP_LOCALES`        comma list of supported locales
     *                         (default: just `APP_LOCALE`); 2+ entries
     *                         activate locale switching + path routing
     *  - `LOCALE_COOKIE`      cookie name for the cookie source
     *                         (default `locale`)
     *  - `LOCALE_PATH_PREFIX` opt out of `/{locale}/...` routing in a
     *                         multi-locale app (default `true`)
     *
     * `LocaleResolver` takes the always-bound `SessionStorage`, but only
     * reads it when a session is already active, so registering it here
     * never eagerly starts a session.
     */
    private static function registerI18n(ContainerBuilder $container, string $projectRoot): void
    {
        $defaultLocale = self::readEnv('APP_LOCALE') ?: 'en';

        $supported = [];
        foreach (\explode(',', self::readEnv('APP_LOCALES')) as $entry) {
            $entry = \trim($entry);
            if ('' !== $entry) {
                $supported[] = $entry;
            }
        }
        if ([] === $supported) {
            $supported = [$defaultLocale];
        } elseif (!\in_array($defaultLocale, $supported, true)) {
            // Keep the default resolvable to its canonical spelling.
            \array_unshift($supported, $defaultLocale);
        }

        $cookieName = self::readEnv('LOCALE_COOKIE') ?: 'locale';
        // Path-prefix routing is meaningful only when there is more than
        // one locale to disambiguate. Gating it here keeps a no-i18n /
        // single-locale app byte-identical to pre-i18n behavior: `/en/...`
        // is never treated as a locale segment and never rewritten.
        $pathPrefix = \count($supported) >= 2 && self::readEnvBool('LOCALE_PATH_PREFIX', true);

        $container->register(Translator::class)
            ->setFactory([Translator::class, 'createForProject'])
            ->setArguments([$projectRoot, $defaultLocale, 'en'])
            ->setPublic(true)
        ;

        $container->register(LocaleResolver::class)
            ->setArguments([
                $supported,
                $defaultLocale,
                $pathPrefix,
                $cookieName,
                new Reference(SessionStorage::class),
            ])
            ->setPublic(true)
        ;
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

    /**
     * Read a boolean-ish env var. Unset/blank → `$default`; `0`, `false`,
     * `off`, `no` (case-insensitive) → false; anything else → true.
     */
    private static function readEnvBool(string $name, bool $default): bool
    {
        $raw = \strtolower(self::readEnv($name));

        if ('' === $raw) {
            return $default;
        }

        return !\in_array($raw, ['0', 'false', 'off', 'no'], true);
    }
}
