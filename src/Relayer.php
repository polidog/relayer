<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\NativeSession;
use Polidog\Relayer\Auth\PasswordHasher;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\FileEtagStore;
use Polidog\Relayer\Router\AppRouter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
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
     * @param string               $projectRoot  Absolute path to the project root (the
     *                                           directory that contains composer.json, .env, and `src/app/`).
     * @param null|AppConfigurator $configurator Optional configurator.
     *                                           Defaults to a bare AppConfigurator with no extra services.
     */
    public static function boot(string $projectRoot, ?AppConfigurator $configurator = null): AppRouter
    {
        $projectRoot = \rtrim($projectRoot, '/');

        self::loadEnv($projectRoot);

        $container = self::buildContainer($projectRoot, $configurator);
        $psr = new InjectorContainer($container);

        $appDir = $projectRoot . '/src/app';
        $isDev = self::isDev();

        $router = AppRouter::create(
            $appDir,
            autoCompilePsx: $isDev,
        );
        $router->setContainer($psr);

        return $router;
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
