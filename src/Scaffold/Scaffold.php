<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

/**
 * The Relayer project skeleton, as a pure data definition.
 *
 * No filesystem I/O lives here so the layout can be unit-tested directly and
 * {@see InitCommand} stays a thin, idempotent writer around it. The skeleton
 * is the smallest thing that boots: one entrypoint, one layout, one page, an
 * empty AppConfigurator extension point, and the convention configs Relayer
 * auto-loads.
 *
 * `init` runs *inside* a project that has already `composer require`d the
 * framework, so this never emits a `composer.json` — it only describes the
 * source tree ({@see files()}) and the minimal, additive patch the project's
 * existing `composer.json` needs ({@see composerPatch()}).
 *
 * STRUCTURE_VERSION is stamped into `composer.json` (`extra.relayer
 * .structure_version`) so a future `upgrade` command can tell which skeleton
 * shape a project was scaffolded against and migrate it forward. Bump it
 * whenever the generated layout changes shape; the migration engine itself is
 * intentionally not built until a v2 layout exists.
 */
final class Scaffold
{
    /**
     * Shape version of the layout {@see files()} produces. A project records
     * the value in effect when it was scaffolded; `upgrade` (future) diffs
     * the recorded value against this constant.
     */
    public const int STRUCTURE_VERSION = 1;

    /**
     * The skeleton source tree: relative path => file contents. POSIX
     * separators, relative to the project root. No `composer.json` — `init`
     * patches the existing one instead (see {@see composerPatch()}).
     *
     * @return array<string, string>
     */
    public static function files(): array
    {
        return [
            '.env' => self::env(),
            '.gitignore' => self::gitignore(),
            'README.md' => self::readme(),
            'public/index.php' => self::indexPhp(),
            'config/services.yaml' => self::servicesYaml(),
            'src/AppConfigurator.php' => self::appConfigurator(),
            'src/Pages/layout.psx' => self::layoutPsx(),
            'src/Pages/page.psx' => self::pagePsx(),
        ];
    }

    /**
     * The additive `composer.json` patch `init` must ensure is present. Every
     * entry is merged non-destructively (existing user values win; only
     * missing keys / array members are added), so re-running `init` is a
     * no-op once applied.
     *
     * @return array{
     *     autoload: array{psr-4: array<string, string>},
     *     scripts: array<string, list<string>>,
     *     extra: array{relayer: array{structure_version: int}}
     * }
     */
    public static function composerPatch(): array
    {
        $publish = 'Polidog\UsePhp\Installer\AssetInstaller::publish';

        return [
            'autoload' => [
                'psr-4' => ['App\\' => 'src/'],
            ],
            'scripts' => [
                'post-install-cmd' => [$publish],
                'post-update-cmd' => [$publish],
            ],
            'extra' => [
                'relayer' => ['structure_version' => self::STRUCTURE_VERSION],
            ],
        ];
    }

    private static function env(): string
    {
        return <<<'ENV'
            # Relayer reads APP_ENV: `dev` enables on-the-fly PSX compilation,
            # request profiling and traceable decorators. Unset (or any other
            # value) is treated as production.
            APP_ENV=dev

            # Set DATABASE_DSN to auto-wire the Db layer. It is passed
            # straight to PDO (no %placeholder% expansion), e.g.:
            # DATABASE_DSN=mysql:host=127.0.0.1;dbname=app
            # DATABASE_USER=app
            # DATABASE_PASSWORD=secret
            # SQLite needs an ABSOLUTE path — PDO resolves a relative DSN
            # path against the process cwd: DATABASE_DSN=sqlite:/srv/app/var/app.db
            # Set USEPHP_SNAPSHOT_SECRET in production if any page serializes
            # snapshot state (a long random string).

            ENV;
    }

    private static function gitignore(): string
    {
        return <<<'GITIGNORE'
            /vendor/
            /var/
            /public/usephp.js
            /.env.local
            /.env.*.local

            GITIGNORE;
    }

    private static function readme(): string
    {
        return <<<'README'
            # Relayer application

            A [Relayer](https://github.com/polidog/relayer) application.

            ## Run

            ```bash
            composer install
            php -S 127.0.0.1:8000 -t public
            ```

            Then open <http://127.0.0.1:8000>.

            ## Layout

            ```
            .env                   APP_ENV=dev
            composer.json
            config/
              services.yaml        Symfony DI registrations (auto-loaded)
            public/
              index.php            single entrypoint: Relayer::boot()->run()
            src/
              AppConfigurator.php  register your services here
              Pages/               file-based routes (Next.js App Router-style)
                layout.psx
                page.psx
            ```

            ## Production

            `APP_ENV=dev` compiles `.psx` on the fly. For deploys, unset (or
            change) `APP_ENV` and pre-compile once:

            ```bash
            vendor/bin/usephp compile src/Pages
            ```

            README;
    }

    private static function indexPhp(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use App\AppConfigurator;
            use Polidog\Relayer\Relayer;

            require_once __DIR__ . '/../vendor/autoload.php';

            Relayer::boot(__DIR__ . '/..', new AppConfigurator(__DIR__ . '/..'))
                ->run();

            PHP;
    }

    private static function servicesYaml(): string
    {
        return <<<'YAML'
            services:
              _defaults:
                autowire: true
                autoconfigure: true
                public: true

              # Register your application services here, e.g.:
              # App\Service\:
              #   resource: '../src/Service/'

            YAML;
    }

    private static function appConfigurator(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Polidog\Relayer\AppConfigurator as BaseAppConfigurator;
            use Symfony\Component\DependencyInjection\ContainerBuilder;

            /**
             * Application service registrations.
             *
             * Anything registered here participates in autowiring; the
             * framework applies autowire + public defaults after configure()
             * runs, so a bare register() call is usually enough. The project
             * root is available as $this->projectRoot.
             */
            final class AppConfigurator extends BaseAppConfigurator
            {
                public function configure(ContainerBuilder $container): void
                {
                    // Register or override services here.
                }
            }

            PHP;
    }

    private static function layoutPsx(): string
    {
        return <<<'PSX'
            <?php

            declare(strict_types=1);

            namespace App\Layouts;

            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;

            final class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    return (
                        <div>
                            <header>
                                <a href="/">Relayer App</a>
                            </header>
                            <main>{$this->getChildren()}</main>
                        </div>
                    );
                }
            }

            PSX;
    }

    private static function pagePsx(): string
    {
        return <<<'PSX'
            <?php

            declare(strict_types=1);

            use Polidog\UsePhp\Html\H;

            return fn () => <section>
                <h1>It works</h1>
                <p>
                    Edit <code>src/Pages/page.psx</code> to change this page. Add
                    routes by creating more <code>page.psx</code> files under
                    <code>src/Pages/</code> (nested directories become path
                    segments; <code>[id]</code> directories are dynamic).
                </p>
            </section>;

            PSX;
    }
}
