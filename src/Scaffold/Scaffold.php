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
    public const int STRUCTURE_VERSION = 5;

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
            // Co-versioned agent conventions: ships with this framework
            // version so it cannot drift. RELAYER.md is the substance;
            // AGENTS.md and CLAUDE.md are 2-line pointers to it, because
            // those are the filenames agent tools / Claude Code auto-read.
            // All are skip-if-exists, so a project's own AGENTS.md /
            // CLAUDE.md is never clobbered.
            'RELAYER.md' => self::relayerMd(),
            'AGENTS.md' => self::agentsPointer(),
            'CLAUDE.md' => self::claudeMdPointer(),
            // Claude Code task tooling, co-versioned exactly like
            // RELAYER.md (ships in the framework, skip-if-exists, cannot
            // drift). RELAYER.md stays the single source of truth; these
            // are thin, trigger-scoped entrypoints that defer to it.
            '.claude/skills/relayer-routing/SKILL.md' => self::claudeRoutingSkill(),
            '.claude/agents/relayer-reviewer.md' => self::claudeReviewerAgent(),
            'public/index.php' => self::indexPhp(),
            'config/services.yaml' => self::servicesYaml(),
            'src/AppConfigurator.php' => self::appConfigurator(),
            'src/Pages/layout.psx' => self::layoutPsx(),
            'src/Pages/page.psx' => self::pagePsx(),
            // A minimal dev container so `docker compose up --build` boots
            // the app with no host PHP. All skip-if-exists like the rest.
            'Dockerfile' => self::dockerfile(),
            'php.ini' => self::phpIni(),
            'compose.yaml' => self::compose(),
            '.dockerignore' => self::dockerignore(),
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

    /**
     * The structure migration map: the version at which each skeleton file
     * was *introduced* => the relative paths added at that version.
     *
     * Every layout change so far has been purely additive (new files, never
     * a move/rename/rewrite/delete), so a migration step is just "ensure
     * these files exist" — `upgrade` writes them skip-if-exists, pulling the
     * contents from {@see files()} (the single source of truth; this map
     * only groups paths by the version that added them). The v1 baseline
     * files ({@see files()} minus every path here — `.env`, the `src/`
     * tree, `public/index.php`, …) need no step: any project carrying
     * the `structure_version` marker was scaffolded with them.
     *
     * `upgrade` applies the steps for `recorded + 1 .. STRUCTURE_VERSION`
     * in order. A future non-additive delta gets its own version key here
     * and the writer learns that one shape — the ordered-step structure is
     * the seam for it.
     *
     * Invariant (asserted in tests): keys are exactly `2 ..
     * STRUCTURE_VERSION`, every path is a {@see files()} key, and no path
     * repeats across versions.
     *
     * @return array<int, list<string>>
     */
    public static function migrations(): array
    {
        return [
            2 => ['RELAYER.md', 'AGENTS.md'],
            3 => ['Dockerfile', 'php.ini', 'compose.yaml', '.dockerignore'],
            4 => [
                '.claude/skills/relayer-routing/SKILL.md',
                '.claude/agents/relayer-reviewer.md',
            ],
            5 => ['CLAUDE.md'],
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

            Or, with no host PHP:

            ```bash
            docker compose up --build
            ```

            Then open <http://localhost:8000>.

            ## Layout

            ```
            .env                   APP_ENV=dev
            composer.json
            RELAYER.md             agent/LLM coding conventions (co-versioned)
            AGENTS.md              auto-read pointer → RELAYER.md
            CLAUDE.md              auto-read pointer → RELAYER.md
            .claude/               Claude Code skill + reviewer agent (co-versioned)
            Dockerfile             FrankenPHP (PHP 8.5) image
            php.ini                PHP overrides (loaded via conf.d)
            compose.yaml           `docker compose up` → http://localhost:8000
            .dockerignore
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

            `APP_ENV=dev` compiles `.psx`, scans routes, and rebuilds the
            DI container on the fly. For deploys, unset (or change)
            `APP_ENV` and precompile all three once:

            ```bash
            composer install --no-dev --classmap-authoritative
            vendor/bin/usephp compile src/Pages      # .psx  -> compiled PHP
            vendor/bin/relayer routes:compile         # route map -> PHP
            vendor/bin/relayer container:compile      # DI container -> PHP
            ```

            Each step is presence-gated: a missing artifact degrades to
            the live path rather than breaking. In production also set
            OPcache `validate_timestamps=0` — the production block in
            `php.ini` documents this.

            README;
    }

    private static function relayerMd(): string
    {
        return <<<'MD'
            # Relayer — agent coding conventions

            Authoritative conventions for writing code in this
            [Relayer](https://github.com/polidog/relayer) app. Generated by
            `relayer init` and **co-versioned with the installed framework**
            (it ships inside `polidog/relayer`, so it does not drift). Run
            `vendor/bin/relayer routes` to see the project's actual route
            map. Full docs: `README.md` of the framework.

            ## Philosophy

            Minimal-first. Add the thinnest thing that satisfies the
            requirement. No new Composer dependencies, no Node/build step,
            no convenience/hybrid layers "just in case".

            ## Routing — `src/Pages/` (Next.js App Router-style)

            - `page.psx` (or `.php`) = a route; `layout.psx` wraps nested
              pages; root `error.psx` is the shared error page for any
              HTTP error (404/403/500…, raised via `$ctx->abort()` /
              `notFound()`); a `[param]` directory is a dynamic segment.
              A directory is a page **or** a `route.php`, never both.
            - Function page: `return fn (PageContext $ctx, MyService $s) =>
              <section/>;` — or two-level: `return function (PageContext
              $ctx) { ...; return fn () => <section/>; };`
            - Class page: `final class X extends PageComponent { public
              function render(): Element { ... } }`.
            - Args are autowired **by type**: `PageContext`, `Request`,
              `Identity` (nullable = optional; a non-nullable `Identity`
              means the page is auth-required), and container services.
              Never read `$_GET/$_POST/$_SERVER` — take a `Request`.
            - Server actions: `$ctx->action('save', fn (array $form) =>
              ...)` or `PageComponent::action([$this, 'm'])`. CSRF is
              automatic; the handler runs before `render()`. Redirect with
              `$ctx->redirect('/path')`.

            ## API routes — `route.php`

            ```php
            use Polidog\Relayer\Http\Response;

            return [
                'GET'  => fn (MyRepo $r) => Response::json($r->all()),
                'POST' => fn (Request $req) => Response::json(['ok' => true], 201),
            ];
            ```

            Keys are HTTP methods (case-insensitive), values are autowired
            closures (same resolver as pages). A handler **must return a
            `Response`**: `Response::json($data, $status)` /
            `Response::text()` / `Response::noContent()` /
            `Response::redirect()` — status and headers are always explicit
            (there is no raw-data return path). `OPTIONS` and `HEAD` are
            synthesized when not declared (undeclared `OPTIONS` -> `204` +
            `Allow`; undeclared `HEAD` runs `GET` without the body); an
            explicit handler for either wins. Unknown method -> `405` +
            `Allow` (JSON). An auth failure -> JSON `401`/`403`. The file
            must **only return the map** (no class/function declarations —
            re-evaluated per request).

            ## Middleware — `src/Pages/middleware.php` (optional)

            ```php
            return function (Request $request, Closure $next): void {
                // inspect / set headers, then continue …
                $next($request);
                // … or don't call $next to short-circuit (401, 429, …)
            };
            ```

            One closure, no chain runner — compose by hand. Declaration-free
            like `route.php`. For CORS use the provided middleware, don't
            hand-roll it: `return Cors::middleware(['origins' => ['*']]);`.

            ## React islands (rich-UI escape hatch)

            In PSX: `{Island::mount('Chart', ['points' => $data])}`. Add the
            loader once via the document:
            `$document->addHeadHtml(Island::loaderScript($nonce))`. You own
            the React bundle (vite/esbuild); it calls
            `window.relayerIslands.register('Chart', (el, props) =>
            createRoot(el).render(<Chart {...props} />))`. Island↔server
            interaction = `fetch` your own `route.php` endpoints. No SSR.

            ## Auth / cache / validation

            - `#[Auth(roles: ['admin'])]` on class pages, or
              `$ctx->requireAuth(['admin'])` in function pages.
            - `#[Cache(maxAge: 60, etag: 'v1')]` or `$ctx->cache(new
              Cache(...))`.
            - `Validator::object([...])->safeParse($input)`.

            ## Services

            Register in `config/services.yaml` (auto-loaded) or
            `App\AppConfigurator::configure()`. Autowire + public by
            default. Setting `DATABASE_DSN` auto-wires the Db layer —
            type-hint `Polidog\Relayer\Db\Database`.

            ## Do NOT

            - Add a Node/build step to the framework, or new Composer deps.
            - Put both a page and `route.php` in one directory.
            - Read superglobals in pages/handlers — take the `Request`.
            - Declare classes/functions in `route.php` / `middleware.php`.
            - Return raw data from a `route.php` handler — return a
              `Response` (`Response::json(...)`); raw data is a hard error.
            - Hand-roll CORS — use the provided `Cors` middleware.
            - Hand-edit `extra.relayer.structure_version` in composer.json.

            MD;
    }

    private static function agentsPointer(): string
    {
        return self::pointerMd('AGENTS.md');
    }

    private static function claudeMdPointer(): string
    {
        return self::pointerMd('CLAUDE.md');
    }

    /**
     * A thin convention pointer. `AGENTS.md` and `CLAUDE.md` are the
     * filenames agent tools / Claude Code auto-read; each only points at
     * the substantive {@see relayerMd()} so the conventions actually reach
     * the agent without forking them — and so the two pointers cannot
     * drift from each other.
     */
    private static function pointerMd(string $heading): string
    {
        return <<<MD
            # {$heading}

            This is a Relayer project. The authoritative coding conventions
            for agents/LLMs live in **[RELAYER.md](./RELAYER.md)** — read it
            before writing pages, API routes, middleware, or islands.

            Run `vendor/bin/relayer routes` to see the actual route map.

            MD;
    }

    private static function claudeRoutingSkill(): string
    {
        return <<<'SKILL'
            ---
            name: relayer-routing
            description: Use when adding or editing routes in this Relayer app — page.psx/.php pages, route.php API endpoints, src/Pages/middleware.php, or React islands. Encodes the autowiring, Response, and CSRF/action contracts that are easy to get wrong.
            ---

            # Relayer routing

            `RELAYER.md` at the project root is the authoritative spec —
            read it for the full model. This is the short, do-the-task
            version; when the two ever disagree, RELAYER.md wins.

            ## Where routes live

            `src/Pages/` is file-based (Next.js App Router-style). A
            directory is a **page** (`page.psx`/`page.php` + optional
            `layout.psx`) **or** an API endpoint (`route.php`) — never
            both. `[param]` directories are dynamic segments; the root
            `error.psx` renders any HTTP error (404/403/500…) raised via
            `$ctx->abort()` / `notFound()`.

            ## Pages

            Function page (preferred — the thinnest form):

            ```php
            return fn (PageContext $ctx, MyService $s) => <section>…</section>;
            ```

            Class page: `final class X extends PageComponent { public
            function render(): Element { … } }`.

            - Arguments autowire **by type**: `PageContext`, `Request`,
              `Identity`, and container services. A nullable `?Identity`
              = optional auth; a non-nullable `Identity` makes the page
              auth-required.
            - Never read `$_GET` / `$_POST` / `$_SERVER` — take a
              `Request`.
            - Forms: `$ctx->action('save', fn (array $form) => …)` — CSRF
              is automatic and the handler runs before render. Redirect
              with `$ctx->redirect('/x')`.

            ## API routes — `route.php`

            ```php
            use Polidog\Relayer\Http\Response;

            return [
                'GET'  => fn (MyRepo $r) => Response::json($r->all()),
                'POST' => fn (Request $req) => Response::json(['ok' => true], 201),
            ];
            ```

            - A method-keyed map of autowired closures. Every handler
              **must return a `Response`**
              (`Response::json/text/noContent/redirect`) — returning raw
              data is a hard error.
            - The file may **only return the map**: no class/function
              declarations (it is re-evaluated per request).
              `OPTIONS`/`HEAD` are synthesized when undeclared.

            ## Middleware — `src/Pages/middleware.php` (optional)

            ```php
            return function (Request $request, Closure $next): void {
                $next($request);          // omit to short-circuit (401, 429, …)
            };
            ```

            One closure, declaration-free. For CORS use
            `Cors::middleware([...])` — never hand-roll it.

            ## React islands

            In PSX: `{Island::mount('Chart', ['points' => $data])}`. You
            own the React bundle; island↔server talk is `fetch` to your
            own `route.php` endpoints. No SSR.

            ## Before you finish

            Run `vendor/bin/relayer routes` and confirm the new route
            shows up. Stay minimal — add the thinnest thing that works:
            no new Composer deps, no Node/build step, no "just in case"
            layers.

            SKILL;
    }

    private static function claudeReviewerAgent(): string
    {
        return <<<'AGENT'
            ---
            name: relayer-reviewer
            description: Reviews changes in this Relayer app against the framework conventions in RELAYER.md — the routing model, the route.php Response contract, CSRF/actions, the no-superglobals rule, and the minimal-design philosophy. Use after editing pages, route.php, middleware, or services, or before opening a PR.
            tools: Read, Grep, Glob, Bash
            ---

            You review code in a Relayer application for conformance to
            the framework's conventions. The authoritative spec is
            `RELAYER.md` at the project root — read it first, then review
            the changes (default to the unstaged / current-branch diff
            unless told otherwise).

            Flag, each with `file:line` and the concrete fix, any of:

            - A directory containing **both** a page and `route.php`.
            - A `route.php` handler returning raw data instead of a
              `Response` (`Response::json/text/noContent/redirect`), or a
              class/function declared in `route.php` or `middleware.php`.
            - Reading `$_GET` / `$_POST` / `$_SERVER` / `$_COOKIE` in a
              page or handler instead of taking a `Request`.
            - Hand-rolled CORS instead of `Cors::middleware(...)`.
            - A hand-edited `extra.relayer.structure_version` in
              `composer.json`.
            - A new Composer dependency or a Node/build step added for
              something the framework already covers, or convenience /
              hybrid layers added "just in case" (breaks the
              minimal-design rule).
            - Auth done ad hoc instead of `#[Auth]` /
              `$ctx->requireAuth()`, or services bypassing autowiring /
              `AppConfigurator`.

            Be specific and terse. Report only real violations and their
            fix; if the change is clean against RELAYER.md, say so
            plainly. Do not invent rules beyond RELAYER.md and the list
            above.

            AGENT;
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

    private static function dockerfile(): string
    {
        return <<<'DOCKER'
            # Relayer app — FrankenPHP image. The default .env sets
            # APP_ENV=dev, which compiles .psx on the fly, so the image
            # needs no build step. For production, unset APP_ENV and
            # precompile once:
            #   vendor/bin/usephp compile src/Pages   # .psx -> .psx.php
            #   vendor/bin/relayer routes:compile      # route artifact
            #   vendor/bin/relayer container:compile   # DI container
            # All are pure build steps; prod then reads the artifacts
            # instead of scanning/compiling/rebuilding per request.
            #
            # FrankenPHP serves /app/public through its bundled Caddy in
            # classic (per-request) mode, so Relayer's public/index.php
            # front controller works as-is — no framework changes. Worker
            # mode (app kept booted between requests) is a future option.
            FROM dunglas/frankenphp:php8.5

            # curl and pdo_sqlite ship enabled in the base image.
            # pdo_mysql matches the DATABASE_DSN example in .env and the
            # commented db service in compose.yaml; zip lets composer
            # install from dist. For PostgreSQL append pdo_pgsql here.
            RUN install-php-extensions pdo_mysql zip

            COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

            WORKDIR /app

            # Install dependencies in their own layer so editing app code
            # doesn't reinstall them. --no-scripts because the post-install
            # hook (usephp asset publisher) needs the app source, which is
            # copied next; the second install runs it with sources present.
            COPY composer.* ./
            RUN composer install --no-interaction --prefer-dist \
                --no-scripts --no-autoloader

            COPY . .
            RUN composer install --no-interaction --prefer-dist

            # php.ini is loaded as a conf.d override (last, so it wins);
            # edit the project's php.ini, not this path. Applied after the
            # Composer steps so build-time Composer keeps its own memory
            # limit rather than the runtime override.
            COPY php.ini "$PHP_INI_DIR/conf.d/zz-relayer.ini"

            # Serve on :8000 to match compose.yaml and the README. With
            # no hostname FrankenPHP also skips auto-HTTPS, which is what
            # you want for local development.
            ENV SERVER_NAME=:8000
            EXPOSE 8000

            DOCKER;
    }

    private static function phpIni(): string
    {
        return <<<'INI'
            ; Relayer app — PHP overrides. The Dockerfile copies this into
            ; $PHP_INI_DIR/conf.d/ so it loads LAST and overrides the base
            ; image defaults: list only the directives you want to change,
            ; not a full php.ini. APP_ENV (in .env), not this file, drives
            ; the framework's dev/prod behaviour.

            expose_php = Off
            memory_limit = 256M

            ; File uploads (keep the two in sync; post_max_size >= upload):
            upload_max_filesize = 16M
            post_max_size = 16M

            ; OPcache. The defaults below are dev-safe (timestamps are
            ; validated, so edited files reload). For production, run the
            ; three precompile steps so there is nothing to compile per
            ; request:
            ;   vendor/bin/usephp compile src/Pages    ; .psx  -> PHP cache
            ;   vendor/bin/relayer routes:compile       ; route map -> PHP
            ;   vendor/bin/relayer container:compile     ; DI container -> PHP
            ; then uncomment the production block.
            opcache.memory_consumption = 128
            opcache.interned_strings_buffer = 16
            ; Must exceed the app's total PHP file count (vendor tree +
            ; one compiled file per .psx page). PHP rounds up to a prime.
            opcache.max_accelerated_files = 16000

            ; --- Production (uncomment; needs the precompile steps above) ---
            ; Everything is precompiled to PHP, so stop stat()-ing files
            ; and never expire the cache — revalidation = redeploy.
            ;opcache.validate_timestamps = 0
            ;opcache.memory_consumption = 256

            INI;
    }

    private static function compose(): string
    {
        return <<<'YAML'
            # Relayer app — minimal Compose setup. `docker compose up
            # --build`, then open http://localhost:8000.
            services:
              app:
                build: .
                ports:
                  - "8000:8000"
                # Mount the source for live edits (APP_ENV=dev recompiles
                # .psx on the fly). The bare /app/vendor volume keeps the
                # image's installed dependencies from being hidden by the
                # host checkout (which has no vendor/). Remove both for an
                # immutable image.
                # volumes:
                #   - .:/app
                #   - /app/vendor
                # env_file: .env

              # A database is optional. To use one, uncomment this service
              # and set in .env: DATABASE_DSN=mysql:host=db;dbname=app —
              # under Compose the host is the service name "db", not the
              # .env default 127.0.0.1 (which, from the app container,
              # would be the app itself).
              # db:
              #   image: mysql:8
              #   environment:
              #     MYSQL_DATABASE: app
              #     MYSQL_USER: app
              #     MYSQL_PASSWORD: secret
              #     MYSQL_ROOT_PASSWORD: secret
              #   ports:
              #     - "3306:3306"
              #   volumes:
              #     - db-data:/var/lib/mysql

            # volumes:
            #   db-data:

            YAML;
    }

    private static function dockerignore(): string
    {
        return <<<'DOCKERIGNORE'
            /vendor/
            /var/
            /.git/
            /public/usephp.js
            /.env.local
            /.env.*.local

            DOCKERIGNORE;
    }
}
