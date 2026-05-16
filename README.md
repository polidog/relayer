# Relayer

[English](README.md) · [日本語](README.ja.md)

Opinionated, batteries-included framework on top of
[polidog/use-php](https://github.com/polidog/usePHP). Bundles:

- A Next.js App Router-style file-based router (`src/Pages/page.psx`,
  `layout.psx`, dynamic segments, error pages)
- File-based JSON API routes (`src/Pages/.../route.php`) — a method-keyed
  map of autowired handlers, return value → JSON
- Per-page / per-layout external scripts (`$ctx->js()` /
  `PageComponent::addJs()` / `LayoutComponent::addJs()`) emitted at the end
  of `<body>` after the bundle, in declaration order
- React islands (`Island::mount()`) — a rich-UI escape hatch: server-rendered
  shell, client React component, props from PHP, your own bundle
- Optional root middleware (`src/Pages/middleware.php`) wrapping every
  dispatch, plus a ready-made `Cors` middleware
- CSRF-protected server actions (`$ctx->action()` /
  `PageComponent::action()` dispatch form posts to in-page handlers)
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html)
  for service wiring (autowire, YAML/PHP config auto-load)
- [symfony/dotenv](https://github.com/symfony/dotenv) for `.env` loading
  with the standard `.env` / `.env.local` / `.env.{APP_ENV}` cascade
- `#[Cache]` attribute for HTTP cache headers + `If-None-Match` 304 handling
  with pluggable `EtagStore` (file-based default, Redis-ready)
- Session-based authentication: `#[Auth]` attribute / `$ctx->requireAuth()`,
  role checks, password hashing, pluggable `UserProvider` and `SessionStorage`
- [Zod](https://zod.dev/)-style schema validation (`Validator::object()`,
  `safeParse` / `parse`, form-input coercion + per-field errors)
- A dev-only request profiler (`/_profiler` view, no-op in production)

Exposes a single `Relayer::boot()` entrypoint so app code stays small.

## Requirements

- PHP >= 8.5
- [polidog/use-php](https://github.com/polidog/usePHP) ^0.1.0
- [symfony/dependency-injection](https://github.com/symfony/dependency-injection) ^7.1
- [symfony/config](https://github.com/symfony/config) ^7.1
- [symfony/yaml](https://github.com/symfony/yaml) ^7.1
- [symfony/dotenv](https://github.com/symfony/dotenv) ^7.1

## Installation

```bash
composer require polidog/relayer
```

## Scaffold a new project

`relayer init` lays the project structure into the **current directory**. Run
it from your project root after requiring the framework:

```bash
composer require polidog/relayer
vendor/bin/relayer init
composer install
php -S 127.0.0.1:8000 -t public
```

`composer install` (rather than `dump-autoload`) so the `App\` autoload
*and* the publish scripts `init` just added both apply — the latter emits
`public/usephp.js`, which the default document references.

It is idempotent and non-destructive:

- existing files are never overwritten (they are reported as skipped), so it
  is safe to re-run;
- your existing `composer.json` is patched **additively** — it adds the `App\`
  PSR-4 autoload, the usePHP asset-publish scripts, and an
  `extra.relayer.structure_version` marker, and leaves everything else
  untouched.

The `structure_version` marker records which skeleton shape the project was
generated against, so structure migrations can be applied later.

`init` also scaffolds **`RELAYER.md`** — concise, authoritative coding
conventions for agents/LLMs working in the project (file conventions, the
`route.php` / `middleware.php` / `Island` contracts, the minimal-design
philosophy, a "do not" list) — plus a 2-line **`AGENTS.md`** that points at
it (the filename agent tools auto-read). Both ship inside `polidog/relayer`,
so they are **co-versioned with the framework and cannot drift**, and both
are skip-if-exists, so a project's own `AGENTS.md` is never overwritten.
Run `vendor/bin/relayer routes` for the project's actual route map.

## Project Layout

```
your-app/
  .env                 # loaded automatically if present
  composer.json
  config/
    services.yaml      # auto-loaded if present (also services.php / .yml)
  public/
    index.php
  src/
    Pages/             # AppRouter file-based routes live here
      layout.psx
      page.psx
      about/
        page.psx
    AppConfigurator.php # your service registrations (extends Polidog\Relayer\AppConfigurator)
```

## Quick Start

`public/index.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\Relayer\Relayer;

Relayer::boot(__DIR__ . '/..')->run();
```

That's the whole entrypoint. `boot()` will:

1. Load `.env` from the project root (if present) into `$_ENV` / `$_SERVER`.
2. Build a Symfony `ContainerBuilder`, auto-load `config/services.{yaml,yml,php}` if present, then let `AppConfigurator` register services on it.
3. Compile the container and wrap it in a PSR-11 adapter for `AppRouter`.
4. Enable `autoCompilePsx` automatically when `APP_ENV=dev`.

The returned `AppRouter` is fully configured. You can still customize before
running:

```php
$router = Relayer::boot(__DIR__ . '/..');
$router->setJsPath('/assets/app.js');
$router->addCssPath('/assets/style.css');
$router->run();
```

## Environment Variables

Put a `.env` in the project root:

```
APP_ENV=dev
DATABASE_DSN=mysql:host=127.0.0.1;dbname=app;charset=utf8mb4
DATABASE_USER=app
DATABASE_PASSWORD=secret
```

`DATABASE_*` is optional — the database layer is wired only when
`DATABASE_DSN` is set (see [Database](#database)).

`.env` files are loaded through [`symfony/dotenv`](https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files)
with the standard Symfony cascade:

1. `.env`                  — committed defaults
2. `.env.local`            — local overrides (gitignored)
3. `.env.{APP_ENV}`        — per-environment defaults (committed)
4. `.env.{APP_ENV}.local`  — per-environment local overrides (gitignored)

Missing files are skipped silently. Variables already in `$_ENV` /
`$_SERVER` / `getenv()` win over the base `.env`; the `.local` files
override the committed counterparts.

`APP_ENV=dev` (or `development`) enables PSX auto-compilation. Any other value
(including unset) treats the app as production: pre-compile with
`vendor/bin/usephp compile src/Pages` during deploy.

## Routing & Pages

The router scans `src/Pages/` and maps the filesystem to URLs in the spirit of
the Next.js App Router. The conventions:

| File                 | Role                                                                |
| -------------------- | ------------------------------------------------------------------- |
| `page.psx`           | Renders the route. One per directory.                               |
| `layout.psx`         | Wraps every nested page; layouts stack from root to leaf.           |
| `error.psx`          | 404 / unmatched-route fallback (root only).                         |
| `route.php`          | JSON API route (no HTML). Method-keyed handler map. One per directory. |
| `[param]/`           | Dynamic segment; captured into `$this->getParam('param')`.          |

`.psx` is the JSX-style source. The runtime executes the compiled
`*.psx.php` sibling — produced automatically in dev (`APP_ENV=dev`) or by
`vendor/bin/usephp compile src/Pages` at deploy time. Plain `.php` page files
also work and skip the compile step.

### Class-style page

```php
<?php
// src/Pages/users/[id]/page.psx
declare(strict_types=1);

namespace App\Pages\Users;

use App\Service\UserRepository;
use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

final class UserDetailPage extends PageComponent
{
    public function __construct(private readonly UserRepository $users) {}

    public function render(): Element
    {
        $user = $this->users->find($this->getParam('id'));
        return <h1>{$user->name}</h1>;
    }
}
```

Constructor injection runs through the DI container — see
[Injecting Services Into Pages](#injecting-services-into-pages).

### Function-style page

You can `return` a closure instead of declaring a class. The factory closure
is autowired the same way class-style page constructors are: declare any
typed parameter and the framework will inject it.

```php
<?php
// src/Pages/about/page.psx
return fn() => <main><h1>About</h1></main>;
```

Services from the container are resolved by type — `PageContext` is the
per-request handle, every other typed parameter comes from the DI container:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, UserRepository $users): Closure {
    $ctx->metadata(['title' => 'Users']);

    return function () use ($users): Element {
        $list = $users->all();
        return <ul>{...\array_map(fn($u) => <li>{$u->name}</li>, $list)}</ul>;
    };
};
```

The factory closure runs once per request. The inner render closure runs
only when the response is not a `304` — keep heavy work there (see
[Function-style pages: `$ctx->cache()`](#function-style-pages-ctxcache)).

### Layouts

Each `layout.psx` wraps every page beneath it. Layouts stack:

```
src/Pages/
  layout.psx          # outer shell
  dashboard/
    layout.psx        # dashboard frame
    page.psx          # /dashboard
    users/
      page.psx        # /dashboard/users — sees both layouts
```

### Error pages

A root `error.psx` (extending `ErrorPageComponent`) renders 404 responses
inside the root layout. Without one, the framework emits a minimal default.

### API Routes

A `route.php` file is a JSON endpoint instead of a rendered page. It returns
a map keyed by HTTP method; each handler is autowired exactly like a
function-style page factory (`PageContext`, `Request`, `Identity`, and
container services inject by type), and the return value becomes the
response — no layout or HTML pipeline runs.

```php
<?php
// src/Pages/api/users/route.php
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Http\Request;

return [
    'GET'  => fn (UserRepository $users): array => ['users' => $users->all()],
    'POST' => function (Request $req, UserRepository $users): array {
        $users->create($req->allPost());
        return ['ok' => true];
    },
];
```

- Lives in `src/Pages/` alongside pages, with the same `[param]` dynamic
  segments — read them via `$ctx->params['id']`. A directory is a page
  **or** a route, not both (the scanner errors if it finds both).
- The return value is JSON-encoded with `Content-Type: application/json`.
  `null` → `204 No Content`. For errors, set the status first and return a
  body — `\http_response_code(404); return ['error' => '…'];` — the
  handler-chosen status passes through unchanged.
- A request method with no handler gets `405 Method Not Allowed` plus an
  `Allow` header. `HEAD` / `OPTIONS` are not synthesized — declare them
  explicitly if a route needs them.
- `route.php` must only `return` the map (no class/function declarations);
  it is re-evaluated every request.
- Auth uses the same `$ctx->requireAuth()` / `Identity` mechanism as
  pages, but a failure is a JSON `401` (anonymous) or `403` (wrong role) —
  not the HTML-login `302` pages emit. A handler calling `$ctx->redirect()`
  itself still produces a `Location` response (a deliberate handler
  action, not an auth gate).

### Per-page scripts (`$ctx->js()` / `addJs()`)

A page (or any layout above it) can declare its own external scripts
instead of everything riding the one global bundle. Function-style:

```php
return function (PageContext $ctx): Closure {
    $ctx->js('/assets/chart.js', defer: true);

    return fn (): Element => <canvas id="chart"></canvas>;
};
```

Class-style pages and layouts get the same via `$this->addJs(...)`:

```php
final class Dashboard extends LayoutComponent
{
    public function render(): Element
    {
        $this->addJs('/assets/dashboard.js', module: true);
        return <div>{...$this->getChildren()}</div>;
    }
}
```

- Emitted at the **end of `<body>`, after** the main usePHP bundle, in
  declaration order. Layout scripts come before the page's; an outer
  (root) layout before an inner one.
- **src-only by design.** Flags: `defer`, `async`, `module`
  (`type="module"`). For inline JS use `$document->addHeadHtml()` — the
  same hook the Island loader rides on (below).
- **No deduplication** — a layout and a page both declaring the same src
  produce two tags. Declared, not reconciled (mirrors `metadata()`).

### React Islands (rich-UI escape hatch)

When a page genuinely needs a rich client UI the server-rendered
defer/partial model can't express, mount a real React component as an
*island*: the server still owns the page, one node is handed to React with
initial props from PHP.

```php
<?php
// src/Pages/dashboard/page.psx
declare(strict_types=1);

use Polidog\Relayer\React\Island;
use Polidog\Relayer\Router\Component\PageContext;

return fn (PageContext $ctx) => (
    <section>
        <h1>Dashboard</h1>
        {Island::mount('Chart', ['points' => $ctx->params])}
    </section>
);
```

`Island::mount()` renders
`<div data-react-island="Chart" data-react-props='…'></div>`. Add the
framework's tiny, React-agnostic loader once via the document, then your
bundle:

```php
$document->addHeadHtml(Island::loaderScript());
$document->addHeadHtml('<script type="module" src="/islands.js"></script>');
```

You own `islands.js` — build it with your own toolchain (vite / esbuild),
with React bundled in. The contract is one call:

```js
import { createRoot } from 'react-dom/client';
import Chart from './islands/Chart';

window.relayerIslands.register('Chart', (el, props) => {
    createRoot(el).render(<Chart {...props} />);
});
```

- The framework provides **only** the PHP primitive and the loader — it
  stays Node-free. React, JSX, and bundling are yours. The loader finds
  islands (including ones swapped in by usePHP defer/partial, via a
  `MutationObserver`), parses props, and calls your registered mount fn;
  registration and DOM order are interchangeable.
- Props flow **one way** (PHP → initial props). For anything the island
  needs from the server afterwards, `fetch` your JSON API routes
  (`route.php`) — there is no separate island↔server channel.
- Names must be plain identifiers; non-encodable props raise a clear error.
- One intentional residual: there is **no SSR** (client render only — the
  mount node is empty until hydration; render a loading state inside your
  component). `loaderScript()` is an inline `<script>`; under a strict
  `script-src` CSP pass `loaderScript($nonce)` and it is emitted as
  `<script nonce="…">` (the `window.relayerIslands.register` contract is
  unchanged).

### Middleware

An optional root `src/Pages/middleware.php` wraps every page/route
dispatch. It `return`s a single closure `fn(Request $request, Closure
$next)`; call `$next($request)` to continue to the matched route, or
**don't** call it to short-circuit (CORS preflight, rate-limit, maintenance
mode, …):

```php
<?php
// src/Pages/middleware.php
declare(strict_types=1);

use Polidog\Relayer\Http\Request;

return function (Request $request, Closure $next): void {
    if (null === $request->header('x-api-key')) {
        \http_response_code(401);
        echo '{"error":"missing api key"}';
        return; // route never runs
    }
    $next($request);
};
```

- One closure, no chain runner (by design). To run several things, compose
  by hand: `fn ($r, $next) => $a($r, fn ($r) => $b($r, $next))`.
- `require`d fresh each request (declaration-free, like `route.php`); a
  non-closure return is a clear error. The framework defer/profiler
  endpoints deliberately run outside it.

**CORS** ships as a ready-made middleware — the one provided
implementation, not a parallel system:

```php
<?php
// src/Pages/middleware.php
use Polidog\Relayer\Http\Cors;

return Cors::middleware([
    'origins' => ['https://app.example.com'], // or ['*']
    // methods / headers / credentials / maxAge are optional
]);
```

It answers `OPTIONS` preflights with `204` itself and adds
`Access-Control-Allow-Origin` to actual requests. `credentials: true` with
`origins: ['*']` reflects the request Origin (a literal `*` is invalid with
credentials per spec).

### Inspecting routes

`vendor/bin/relayer routes` prints every route Relayer discovers under
`src/Pages` — pages and `route.php` endpoints with their methods — using
the same scanner the router uses:

```
METHODS    PATH            TYPE  FILE
GET,POST   /               page  src/Pages/page.psx
GET,POST   /api/users      api   src/Pages/api/users/route.php
GET,POST   /users/[id]     page  src/Pages/users/[id]/page.psx
```

Pages report `GET,POST` (POST is how server actions / `useState` reach a
page); API routes list their declared methods. A `route.php` that fails to
load is shown as `?` with a warning line, not silently hidden.

## Server Actions (form / CSRF-protected)

Dispatch a form submission to a server-side handler bound to the page
(equivalent to Next.js Server Actions). The token is CSRF-protected and the
handler runs **before** `render()`. Available in both class- and
function-style pages.

### Class-style: `PageComponent::action()`

`PageComponent::action([$this, 'handler'])` returns a CSRF-bound token for a
form's hidden field. Submitting the form invokes the matching method on the
page before `render()`:

```php
public function render(): Element
{
    return (
        <form method="post">
            <input type="hidden" name="_usephp_action" value={$this->action([$this, 'save'])} />
            <input name="title" />
        </form>
    );
}

public function save(array $form): void
{
    // ... handle $form['title']
    header('Location: /dashboard', true, 303); // PRG
    exit;
}
```

Invalid CSRF tokens return a `403`.

### Function-style: `PageContext::action()`

Function-style pages declare server actions through `PageContext::action()`.
The factory closure runs on every request — including the POST that submits
the form — so the action table is rebuilt before dispatch and the token only
needs to carry `(pageId, name)`:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, UserRepository $users): Closure {
    $save = $ctx->action('save', function (array $form) use ($users): void {
        $users->create($form['name']);
        \header('Location: /users', true, 303);
        exit;
    });

    return function () use ($save, $users): Element {
        return (
            <main>
                <ul>{...\array_map(fn($u) => <li>{$u->name}</li>, $users->all())}</ul>
                <form action={$save}>
                    <input name="name" />
                    <button>save</button>
                </form>
            </main>
        );
    };
};
```

The handler receives the POST body as its first argument (with
`_usephp_action` / `_usephp_csrf` stripped). Action names must be unique
per page — registering the same name twice throws.

### Binding arguments

A third `$args` argument binds values into the handler. They are passed
**after** the form body:

```php
// list → positional:   handler($form, 42)
$delete = $ctx->action('delete', function (array $form, int $id) use ($repo): void {
    $repo->delete($id);
}, [$user->id]);

// assoc → named args:   handler(formData: $form, id: 42)
$ctx->action('delete', fn (array $formData, int $id) => $repo->delete($id), ['id' => $user->id]);
```

`$args` is embedded **verbatim** in the base64 action token (it is *not*
signed — tamper detection is the CSRF token's job). Keep bound values to
identifiers and always re-validate authorization/integrity inside the
handler (e.g. verify ownership of the incoming `$id` server-side).

### Re-rendering after a failed submit

A function-style page's factory closure re-runs on every request and the
action handler runs **after** the renderer is built. To re-render the same
page on a validation error, capture state by reference (`&$errors`) and read
the post-dispatch value in the renderer (the typical pairing with
[Validation](#validation)'s `safeParse`; full example in
`example/src/Pages/signup/page.psx`):

```php
return function (PageContext $ctx) use ($schema): Closure {
    $errors = [];
    $save = $ctx->action('save', function (array $form) use ($schema, &$errors): void {
        $result = $schema->safeParse($form);
        if (!$result->success) { $errors = $result->errors; return; }
        // ... on success, PRG redirect (header('Location: ...', true, 303); exit;)
    });

    // $errors is mutated after the action runs → capture by reference
    return function () use ($save, &$errors): Element { /* render $errors */ };
};
```

## Service Registration

You have two complementary ways to register services. Both can be used in the
same project — YAML/PHP files load first, then `AppConfigurator` runs and can
override anything.

### Option A — `config/services.yaml` (auto-loaded)

Drop a `config/services.yaml` next to `composer.json` and the framework picks
it up at boot time. This is the idiomatic Symfony style:

```yaml
# config/services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  App\Service\PdoUserRepository: ~

  App\Service\UserRepository:
    alias: App\Service\PdoUserRepository
```

`config/services.php` (returning a `ContainerConfigurator` closure) and
`config/services.yml` are also accepted.

### Option B — `AppConfigurator` (PHP)

Subclass `AppConfigurator` and register services on the `ContainerBuilder`.
The framework applies autowire + public visibility by default, so a bare
`register()` call is usually enough:

```php
<?php
// src/PagesConfigurator.php
declare(strict_types=1);

namespace App;

use App\Service\UserRepository;
use App\Service\PdoUserRepository;
use Polidog\Relayer\AppConfigurator as BaseConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AppConfigurator extends BaseConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(PdoUserRepository::class);
        $container->setAlias(UserRepository::class, PdoUserRepository::class)
            ->setPublic(true);
    }
}
```

Then pass it to `boot()`:

```php
Relayer::boot(__DIR__ . '/..', new App\AppConfigurator(__DIR__ . '/..'))->run();
```

### Autowire defaults

The framework iterates every `Definition` you register and:

- enables `autowired` if you didn't pass explicit constructor arguments
- forces `public = true` so PSR-11 `get($id)` can fetch it

If you need a private service or fully-manual wiring, configure the
`Definition` explicitly — your settings win.

## Injecting Services Into Pages

Class-based pages get constructor injection automatically. Page classes do
**not** need to be registered in the container — the PSR-11 adapter falls
back to reflection-based autowiring for unregistered classes, resolving each
typed dependency from the Symfony container:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

namespace App\Pages\Users;

use App\Service\UserRepository;
use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

final class UsersPage extends PageComponent
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function render(): Element
    {
        $users = $this->users->all();
        // ...
    }
}
```

You only need to register a Page in `AppConfigurator` if you want non-default
behavior (e.g. service tags, decorators, factory construction).

## Accessing the HTTP Request

Declare a `Polidog\Relayer\Http\Request` parameter on a page (function-style
factory **or** class constructor) and the framework will inject an immutable
snapshot of the current request — pages never need to touch `$_GET`,
`$_POST`, or `$_SERVER` directly.

```php
<?php
// src/Pages/signup/page.psx
declare(strict_types=1);

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, Request $req): Closure {
    $errors = [];

    if ($req->isPost()) {
        $email = $req->post('email') ?? '';
        if (!\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email';
        }
        if ([] === $errors) {
            \header('Location: /thanks', true, 303);
            exit;
        }
    }

    return function () use ($errors, $req): Element {
        // ... render form, echoing $req->post('email') back into the input
    };
};
```

`Request` API (all immutable):

| Method                       | Returns                                       |
| ---------------------------- | --------------------------------------------- |
| `$req->method`               | uppercase HTTP method                         |
| `$req->path`                 | request path (no query string)                |
| `$req->isGet()` / `isPost()` | `bool`                                        |
| `$req->isMethod('PUT')`      | `bool`                                        |
| `$req->post($key)`           | `?string` (null if missing / non-string)      |
| `$req->query($key)`          | `?string`                                     |
| `$req->header($name)`        | `?string` (case-insensitive)                  |
| `$req->allPost()`            | `array<string, mixed>` (raw body)             |
| `$req->allQuery()`           | `array<string, mixed>`                        |
| `$req->allHeaders()`         | `array<string, string>` (lowercased keys)     |

Tests use `new Request(method: 'POST', path: '/signup', post: [...])`
directly — no superglobal manipulation needed.

## Authentication

Session-based authentication ships in the box. You provide a
`UserProvider` (your user lookup) and the framework wires the rest:
password hashing, the session-stored principal, and a request-time
guard that protects pages.

### 1. Implement a `UserProvider`

The provider takes a user-supplied identifier (typically email) and
returns `Credentials` — the `Identity` that will live in the session,
plus the password hash to verify against. Return `null` when the
identifier is unknown.

```php
<?php
declare(strict_types=1);

namespace App\Auth;

use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;

final class PdoUserProvider implements UserProvider
{
    public function __construct(private readonly \PDO $pdo) {}

    public function findByIdentifier(string $identifier): ?Credentials
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, password_hash, roles FROM users WHERE email = ?'
        );
        $stmt->execute([\strtolower(\trim($identifier))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            return null;
        }

        return new Credentials(
            identity: new Identity(
                id: (int) $row['id'],
                displayName: (string) $row['name'],
                roles: \json_decode((string) $row['roles'], true) ?: [],
            ),
            passwordHash: (string) $row['password_hash'],
        );
    }
}
```

### 2. Bind the provider

The framework registers `Authenticator`, `PasswordHasher`
(`NativePasswordHasher` with `PASSWORD_DEFAULT`), and `SessionStorage`
(`NativeSession`) by default. Adding the `UserProvider` binding is all
that's required to opt in:

```yaml
# config/services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  App\Auth\PdoUserProvider: ~

  Polidog\Relayer\Auth\UserProvider:
    alias: App\Auth\PdoUserProvider
```

Apps that don't bind `UserProvider` pay nothing — `Authenticator` is
only registered when the interface is bound, so unrelated projects keep
booting unchanged.

### 3. Log users in

Inject `Authenticator` into the login page and call `attempt()` with
the submitted credentials. Successful authentication rotates the
session id (defends against session fixation) and stores the
`Identity` snapshot.

```php
<?php
// src/Pages/login/page.psx
declare(strict_types=1);

use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, Authenticator $auth): Closure {
    $error = null;

    $login = $ctx->action('login', function (array $form) use ($auth, &$error): void {
        $identity = $auth->attempt(
            (string) ($form['email']    ?? ''),
            (string) ($form['password'] ?? ''),
        );

        if (null === $identity) {
            $error = 'Invalid email or password.';

            return;
        }

        \header('Location: /dashboard', true, 303);
        exit;
    });

    return function () use ($login, $error): Element {
        // ... render form, surface $error as a single generic message
    };
};
```

`Authenticator` API:

| Method                           | Returns           | Notes                                                    |
| -------------------------------- | ----------------- | -------------------------------------------------------- |
| `attempt($id, $password)`        | `?Identity`       | Verify via `UserProvider` + hasher; on success: log in.  |
| `login(Identity $identity)`      | `void`            | Promote an already-resolved principal (SSO, signup).     |
| `logout()`                       | `void`            | Drop the principal, rotate the session id.               |
| `user()`                         | `?Identity`       | Currently-logged-in principal, or `null`.                |
| `check()`                        | `bool`            | Shorthand for `user() !== null`.                         |
| `hasRole($role)` / `hasAnyRole`  | `bool`            | Role probes.                                             |

`attempt()` runs the password hasher even when the identifier is
unknown so an attacker can't enumerate accounts by response time.
A failure always returns `null`; the caller should render a single
generic error rather than disclose which field rejected the input.

### 4. Protect pages

#### Class-style: `#[Auth]`

Attach `Polidog\Relayer\Auth\Auth` to a `PageComponent` subclass. The
guard runs in `InjectorContainer` **before** the page is instantiated
— so an anonymous request never builds the page or its dependencies.

```php
<?php
namespace App\Pages;

use Polidog\Relayer\Auth\Auth;
use Polidog\Relayer\Router\Component\PageComponent;

#[Auth] // any authenticated user
final class DashboardPage extends PageComponent { /* ... */ }

#[Auth(roles: ['admin'])] // role-gated; non-admin gets 403
final class AdminPage extends PageComponent { /* ... */ }

#[Auth(redirectTo: '')] // empty redirect -> 401 instead of 302 (JSON / API)
final class ApiEndpoint extends PageComponent { /* ... */ }
```

| Parameter      | Default     | Effect                                                 |
| -------------- | ----------- | ------------------------------------------------------ |
| `roles`        | `[]`        | One of these roles must be present (empty = any user). |
| `redirectTo`   | `'/login'`  | Where anonymous requests go. Empty string → `401`.     |

Anonymous requests get a `302 Location: /login?next=<requested-path>`
(URL-encoded, same-origin only). Authenticated users lacking the
required role get `403 Forbidden`.

`#[Auth]` is evaluated before `#[Cache]`, so unauthorized requests
never produce a cacheable `304` that could leak to anonymous viewers
through a shared cache. Combining `#[Auth]` + `#[Cache]` is fine —
just prefer `Cache-Control: private` for per-user gated pages.

#### Function-style: `$ctx->requireAuth()` / `Identity` injection

Function-style factories use a declarative guard on `PageContext`:

```php
<?php
// src/Pages/dashboard/page.psx
declare(strict_types=1);

use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx): Closure {
    $user = $ctx->requireAuth(); // throws AuthorizationException on failure

    return fn(): Element => <h1>Welcome, {$user->displayName}</h1>;
};
```

`requireAuth($roles = [], $redirectTo = '/login')` returns the
`Identity` so you can use it inline. AppRouter catches the exception
and produces the same `302` / `401` / `403` response as `#[Auth]`.

For pages that adapt to the authentication state instead of requiring
it, declare `?Identity` on the factory and the framework injects the
current principal (`null` when no one is logged in):

```php
return function (PageContext $ctx, ?Identity $user): Closure {
    $ctx->metadata(['title' => $user?->displayName ?? 'Welcome']);

    return fn(): Element => null !== $user
        ? <p>Hi, {$user->displayName}</p>
        : <a href="/login">Sign in</a>;
};
```

A non-nullable `Identity` parameter is treated as "auth required" and
triggers the same redirect path as `requireAuth()` when anonymous —
equivalent to `#[Auth]` for class-style pages.

### 5. Pluggable parts

The defaults are sensible but swappable. Bind a different
implementation in `services.yaml` (or `AppConfigurator`) to override:

| Interface                                  | Default                  | Override when…                                     |
| ------------------------------------------ | ------------------------ | -------------------------------------------------- |
| `Polidog\Relayer\Auth\UserProvider`        | *(unbound, app-supplied)*| Always — this is your user lookup.                 |
| `Polidog\Relayer\Auth\PasswordHasher`      | `NativePasswordHasher`   | You want a specific algorithm or pepper.           |
| `Polidog\Relayer\Auth\SessionStorage`      | `NativeSession`          | You want Redis / database-backed sessions.         |

`NativePasswordHasher` uses `PASSWORD_DEFAULT` so it tracks whatever
PHP considers strongest on the current build (bcrypt today). Force
argon2id when libargon2 is available:

```php
$container->register(NativePasswordHasher::class)
    ->setArguments([\PASSWORD_ARGON2ID]);
```

`NativeSession` calls `session_start()` lazily on first read/write,
so just resolving the service through DI does not eagerly emit
`Set-Cookie`. It shares `$_SESSION` with the existing CSRF token
machinery — no duplicate session starts.

### Notes

- The auth guard runs in `InjectorContainer` (class-style pages) and
  in the factory-arg resolver (`Identity` injection / `requireAuth`).
  Layouts are not guarded — they're resolved separately. If you need
  layout-level auth state, inject `?Authenticator` into the layout
  constructor and read `$auth?->user()` from `render()`.
- `?next=<path>` on the login redirect is same-origin only — paths
  starting with `//` or an absolute URL are dropped to prevent
  open-redirect bouncing off the login page.
- Sessions are rotated on **both** login and logout. A pre-login
  session id captured by an attacker stops working the moment the
  user authenticates.

## HTTP Cache Headers via `#[Cache]`

Attach `Polidog\Relayer\Http\Cache` to a Page class to control
`Cache-Control` / `Vary` / `ETag` headers. The framework reads the attribute
when AppRouter resolves the page through the container and emits the headers
before the body is written.

```php
<?php
// src/Pages/page.psx
declare(strict_types=1);

namespace App\Pages;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Http\Cache;
use Polidog\UsePhp\Runtime\Element;

#[Cache(
    maxAge: 3600,
    sMaxAge: 86400,
    public: true,
    vary: ['Accept-Language'],
    etag: 'home-v1',
)]
final class HomePage extends PageComponent
{
    public function render(): Element { /* ... */ }
}
```

Supported parameters:

| Parameter         | Effect                                    |
| ----------------- | ----------------------------------------- |
| `maxAge`          | `Cache-Control: max-age=<n>`              |
| `sMaxAge`         | `Cache-Control: s-maxage=<n>` (CDN)       |
| `public`          | `Cache-Control: public`                   |
| `private`         | `Cache-Control: private`                  |
| `noStore`         | `Cache-Control: no-store`                 |
| `noCache`         | `Cache-Control: no-cache`                 |
| `mustRevalidate`  | `Cache-Control: must-revalidate`          |
| `immutable`       | `Cache-Control: immutable`                |
| `vary`            | `Vary: <comma-joined values>`             |
| `etag`            | `ETag: "<value>"` (auto-quoted if raw)    |
| `etagWeak`        | Emit ETag as a weak validator `W/"…"`     |
| `lastModified`    | `Last-Modified: <RFC 7231 GMT date>` (any `strtotime()`-parseable string; UTC recommended) |
| `etagKey`         | Logical key looked up in the configured `EtagStore` (see below). Static `etag` wins when both are set. |

### Conditional GET / `304 Not Modified`

When `etag` or `lastModified` is set, the framework also evaluates the
request's `If-None-Match` / `If-Modified-Since` headers on safe methods
(`GET`, `HEAD`). If the client already has a fresh copy, the response is
short-circuited:

1. cache validation headers (`ETag`, `Last-Modified`, `Cache-Control`, `Vary`)
   are emitted
2. status is set to `304 Not Modified`
3. the request terminates before any body is rendered

ETag comparison follows the weak comparison rules of RFC 7232 §2.3.2, so
`W/"v1"` and `"v1"` match each other and `*` matches any tag.

### Example

```php
#[Cache(
    maxAge: 3600,
    public: true,
    vary: ['Accept-Language'],
    etag: 'home-v1',
    etagWeak: true,
    lastModified: '2025-01-15 10:00:00 UTC',
)]
final class HomePage extends PageComponent { /* ... */ }
```

### Function-style pages: `$ctx->cache()`

PHP attributes only attach to classes, so function-style `page.psx` files
declare their cache policy through `PageContext` instead:

```php
<?php
// src/Pages/feed/page.psx
declare(strict_types=1);

use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx): Closure {
    // Lightweight setup: declare cache, read params. NO DB queries here.
    $ctx->cache(new Cache(maxAge: 60, public: true, etagKey: 'feed'));

    return function () use ($ctx): Element {
        // Heavy work goes here — only runs on cache miss.
        // ... query DB, build the page
    };
};
```

The factory closure runs once per request (lightweight); the inner render
closure runs only when the response is not a `304`. So the 304 short-circuit
saves the inner closure's body — keep DB/expensive work there to get the
same "never touch the database" benefit class-style pages get.

All `#[Cache]` parameters are available on the `Cache` constructor.

### Dynamic ETag via `EtagStore`

A static `etag: 'home-v1'` works for content that only changes on deploy.
For data-driven pages, declare `etagKey:` and let an `EtagStore` resolve the
current value at request time:

```php
#[Cache(maxAge: 60, public: true, etagKey: 'user-list')]
final class UsersPage extends PageComponent { /* ... */ }
```

The framework looks up the key in the registered `EtagStore` *before*
constructing the page. If the client's `If-None-Match` already matches, the
request is short-circuited with `304` and **no page or repository code runs**
— the database is never touched.

Producers (repositories, command handlers) update the stored value when
their data changes:

```php
final class UserRepository
{
    public function __construct(private readonly EtagStore $etags) {}

    public function save(User $user): void
    {
        // ... persist
        $this->etags->set('user-list', \sha1((string) \microtime(true)));
    }
}
```

#### Default backend: `FileEtagStore`

Out of the box the framework registers `FileEtagStore` writing to
`$projectRoot/var/cache/etags/` (one file per `sha1(key)`, atomic
write-then-rename). Zero configuration needed.

#### Custom backend (e.g. Redis)

Implement `Polidog\Relayer\Http\EtagStore` and register your class as
the `EtagStore` alias. For example, with phpredis:

```php
final class RedisEtagStore implements EtagStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'etag:',
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->prefix . $key);
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $etag): void
    {
        $this->redis->set($this->prefix . $key, $etag);
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
```

Then wire it through `services.yaml`:

```yaml
services:
  _defaults:
    autowire: true
    public: true

  Redis:
    factory: ['App\Factory\RedisFactory', 'connect']

  App\Infrastructure\RedisEtagStore: ~

  Polidog\Relayer\Http\EtagStore:
    alias: App\Infrastructure\RedisEtagStore
```

…or in `AppConfigurator::configure()`:

```php
$container->register(RedisEtagStore::class);
$container->setAlias(EtagStore::class, RedisEtagStore::class)->setPublic(true);
```

### Notes / caveats

- The attribute is honored only on `PageComponent` subclasses. Layouts and
  ordinary services with `#[Cache]` are ignored to avoid surprising header
  writes when fetched through the container.
- All header writes are skipped once `headers_sent()` is true.
- The 304 short-circuit issues `exit;` from the PSR-11 adapter. It runs
  *before* the page is instantiated, so neither the page constructor nor its
  injected dependencies execute on a cache hit.
- If you need conditional cache policy (per-request, per-user), set headers
  manually inside `render()` instead.

## Database

A thin PDO wrapper: raw SQL in, plain arrays out. No query builder, no
SQL-file loader — pass SQL with named (`:id`) or positional (`?`)
placeholders directly. It exists to give you four things you'd otherwise
wire by hand: profiler visibility, explicit timeouts, one error type, and
per-request read memoization.

### Enable it

The DB layer is registered **only when `DATABASE_DSN` is set** — apps that
don't use a database pay nothing and don't need to configure anything.

```
DATABASE_DSN=mysql:host=127.0.0.1;dbname=app;charset=utf8mb4
DATABASE_USER=app
DATABASE_PASSWORD=secret
DATABASE_TIMEOUT=5            # connect timeout, seconds (PDO::ATTR_TIMEOUT)
DATABASE_READ_TIMEOUT=10      # MySQL read timeout, seconds (optional)
```

`DATABASE_DSN` is a standard PDO DSN, so SQLite (`sqlite:/path/app.db`),
PostgreSQL (`pgsql:host=...`), etc. all work. `DATABASE_READ_TIMEOUT` is
applied only for `mysql:` DSNs.

### Use it

Take a `Database` dependency in a page or component constructor:

```php
use Polidog\Relayer\Db\Database;

final class UserPage extends PageComponent
{
    public function __construct(private readonly Database $db) {}

    public function render(): string
    {
        $user = $this->db->fetchOne(
            'SELECT id, name FROM users WHERE id = :id',
            ['id' => 42],
        );
        // ...
    }
}
```

| Method                          | Returns                            |
| ------------------------------- | ---------------------------------- |
| `fetchAll($sql, $params)`       | `list<array<string,mixed>>`        |
| `fetchOne($sql, $params)`       | `array<string,mixed>` or `null`    |
| `fetchValue($sql, $params)`     | first column of first row, or `null` |
| `perform($sql, $params)`        | affected row count (`int`)         |
| `lastInsertId($name = null)`    | last insert id (`string`)          |
| `transactional($callback)`      | callback's return value            |

```php
$db->transactional(function (Database $tx): void {
    $tx->perform('INSERT INTO orders (user_id) VALUES (?)', [$userId]);
    $tx->perform('UPDATE users SET order_count = order_count + 1 WHERE id = ?', [$userId]);
});
```

The callback runs inside a transaction — commit on return, rollback +
rethrow on any exception. Use the `$tx` argument it receives so the calls
stay traced and cached.

### What you get for free

- **Errors** — every driver failure is thrown as a single
  `Polidog\Relayer\Db\DatabaseException`; the original `PDOException` is
  kept as the previous exception.
- **Timeouts** — a stuck DB surfaces as a `DatabaseException` within the
  configured timeout instead of hanging the worker.
- **Request-scoped cache** — identical reads (`fetchAll` / `fetchOne` /
  `fetchValue` with the same SQL + params) hit an in-process cache for the
  rest of the request, so a page assembled from several components that
  each need the same lookup makes one round-trip, not N. Any `perform` or
  `transactional` flushes the cache. It is request-scoped only — no TTL,
  no cross-request sharing.
- **Profiler** (dev) — every real query is recorded in the request
  profile as a timed `db.query` / `db.mutate` / `db.transaction` span with
  the SQL and bound params; cache hits show as `db.cache_hit` markers so
  you can see exactly how many round-trips memoization saved. In prod the
  profiler is a no-op, so there's no overhead.

## Validation

`Polidog\Relayer\Validation` is a schema validator inspired by
[Zod](https://zod.dev/) (TypeScript). It coerces and validates input
(form fields always arrive as strings) and returns per-field error
messages in a single pass. No extra dependency.

### Declaring a schema

Build schemas through the `Validator` facade:

```php
use Polidog\Relayer\Validation\Validator;

$schema = Validator::object([
    'email' => Validator::string()->trim()->email(),
    'name'  => Validator::string()->trim()->min(1, 'Name is required.'),
    'age'   => Validator::int()->min(0)->optional(),
    'role'  => Validator::enum(['admin', 'member'])->default('member'),
]);
```

| Factory                        | Schema                                                  |
| ------------------------------ | ------------------------------------------------------- |
| `Validator::string()`          | String. `min/max/length/regex/email/url/trim/lower/upper` |
| `Validator::int()`             | Integer; coerces numeric strings. `min/max/positive/nonNegative` |
| `Validator::float()`           | Float; coerces numeric strings                          |
| `Validator::bool()`            | Boolean                                                 |
| `Validator::enum([...])`       | One of the allowed values; `literal()` for a single one |
| `Validator::object([...])`     | Assoc array; unknown keys stripped by default, `passthrough()` keeps them |
| `Validator::array($element)`   | Validates every element against `$element`              |
| `Validator::email()` / `url()` | Shortcuts for `string()->trim()->email()` / `url()`     |

Modifiers available on every schema (immutable — each returns a clone, so a
base schema is reusable as a building block):

| Modifier                     | Meaning                                              |
| ---------------------------- | ---------------------------------------------------- |
| `optional()`                 | Absent input becomes `null`; no further checks       |
| `nullable()`                 | Allows `null` (the key itself is still required)     |
| `default($value)`            | Value used when input is absent                      |
| `required(?$message)`        | Force required + override the "absent" message       |
| `refine($predicate, $msg)`   | Arbitrary extra validation predicate                 |
| `transform($fn)`             | Final transform after a value validates              |

For `StringSchema` / `IntSchema` / `EnumSchema` an empty string counts as
"not provided", so `optional` / `required` / `default` behave intuitively
with form inputs.

### Parsing

```php
$result = $schema->safeParse($_POST);

if ($result->success) {
    $data = $result->data;          // coerced values
} else {
    $errors = $result->errors;      // ['email' => '...', 'address.zip' => '...']
}
```

- `safeParse($input): ParseResult` — never throws on validation errors;
  branch on `success`.
- `parse($input): mixed` — throws `ParseError` (carrying `$errors`) on
  failure.
- Nested `object` errors use dot paths (`address.zip`).

### With form actions

The typical use is alongside `$ctx->action()`
(`example/src/Pages/signup/page.psx`):

```php
$schema = Validator::object([
    'name'     => Validator::string()->trim()->min(1, 'Name is required.'),
    'email'    => Validator::string()->trim()->email(),
    'password' => Validator::string()->min(8, 'Password must be at least 8 characters.'),
]);

$signup = $ctx->action('signup', function (array $form) use ($schema, &$errors): void {
    $result = $schema->safeParse($form);
    if (!$result->success) {
        $errors = $result->errors;   // hand field errors to the view
        return;
    }
    // $result->data is coerced
});
```

## Profiler

A dev-only request profiler. Each request is recorded as a `Profile`
(URL, method, status, event timeline) and inspectable through the
`/_profiler` web view. **Zero cost in production** — user code can take a
`Profiler` dependency without caring about the environment.

### How it works

`Profiler::class` is always bound in DI:

- **prod** (`APP_ENV` not dev/development) → `NullProfiler`. Every method is
  a no-op, callable without an `if profiler enabled` branch.
- **dev** (`APP_ENV=dev`) → `RecordingProfiler`. Events accumulate on the
  `Profile` and are persisted by `FileProfilerStorage` to
  `<projectRoot>/var/cache/profiler` as JSON at end of request.

In dev, the Traceable decorators wrap AppRouter / Database / EtagStore /
SessionStorage / Authenticator and feed spans like `db.query`,
`cache.etag_*`, and `session.*` into the profile automatically.
`<X defer />` sub-requests are linked to their parent via `parentToken`.

### Web view

`TraceableAppRouter` intercepts `/_profiler` *before* normal dispatch (so
the profiler never profiles itself):

| URL                  | Content                                                 |
| -------------------- | ------------------------------------------------------- |
| `/_profiler`         | Recent requests (defer sub-requests folded into parent) |
| `/_profiler/<token>` | One request in detail (event timeline + sub-requests)   |

Pure HTML — no JS, no external CSS — so it works offline.

### Instrumenting from code

Take a `Profiler` in any page/service constructor:

```php
use Polidog\Relayer\Profiler\Profiler;

public function __construct(private readonly Profiler $profiler) {}

// one-shot event
$this->profiler->collect('app', 'cache warmed', ['keys' => 12]);

// timed span (finalized by stop())
$span = $this->profiler->start('app', 'heavy compute');
$result = $this->compute();
$span->stop(['rows' => \count($result)]);
```

The same calls are no-ops under `NullProfiler`, so no environment branching
is needed.

### Clearing stored profiles

`vendor/bin/relayer profiler:clear` deletes the JSON profiles under
`var/cache/profiler` so `/_profiler` starts fresh. It only removes the
`*.json` the storage writes (the directory is recreated on the next dev
request); a missing cache is reported and treated as success, so re-running
is always safe.

## Source Layout

| Namespace                                              | Purpose                                                                |
| ------------------------------------------------------ | ---------------------------------------------------------------------- |
| `Polidog\Relayer\Relayer`                    | Boot entrypoint (env load + DI build + router wire-up).                |
| `Polidog\Relayer\AppConfigurator`              | Extension point for service registrations.                             |
| `Polidog\Relayer\InjectorContainer`            | PSR-11 adapter with reflection autowire + 304 short-circuit.           |
| `Polidog\Relayer\Router\AppRouter`             | File-based router for `src/Pages/` (PSR-11 container–driven).            |
| `Polidog\Relayer\Router\Component\*`           | `PageComponent`, `ErrorPageComponent`, `FunctionPage`, `PageContext`.  |
| `Polidog\Relayer\Router\Layout\*`              | `LayoutComponent` + nested layout rendering.                           |
| `Polidog\Relayer\Router\Document\*`            | HTML document wrapper / metadata.                                      |
| `Polidog\Relayer\Router\Form\*`                | CSRF tokens + form action dispatcher.                                  |
| `Polidog\Relayer\Router\Routing\*`             | Page scanner, route table, matcher.                                    |
| `Polidog\Relayer\Db\Database`                  | Minimal SQL contract (default: `PdoDatabase`, cached, dev-traced).     |
| `Polidog\Relayer\Db\DatabaseException`         | The single error type the DB layer raises.                             |
| `Polidog\Relayer\Http\Cache`                   | `#[Cache]` attribute.                                                  |
| `Polidog\Relayer\Http\CachePolicy`             | Header emission + conditional GET evaluation.                          |
| `Polidog\Relayer\Http\EtagStore`               | Pluggable ETag storage interface.                                      |
| `Polidog\Relayer\Http\FileEtagStore`           | Default file-backed `EtagStore` implementation.                        |
| `Polidog\Relayer\Auth\Auth`                    | `#[Auth]` attribute.                                                   |
| `Polidog\Relayer\Auth\Authenticator`           | Session-based authentication orchestrator.                             |
| `Polidog\Relayer\Auth\Identity` / `Credentials`| Principal + login-handshake value objects.                             |
| `Polidog\Relayer\Auth\UserProvider`            | App-supplied user lookup interface.                                    |
| `Polidog\Relayer\Auth\PasswordHasher`          | Hashing interface (default: `NativePasswordHasher`).                   |
| `Polidog\Relayer\Auth\SessionStorage`          | Session storage interface (default: `NativeSession`).                  |
| `Polidog\Relayer\Validation\Validator`         | Zod-style schema builder facade (`safeParse` / `parse`).               |
| `Polidog\Relayer\Validation\Schema`            | Schema base + types (string/int/float/bool/enum/array/object).         |
| `Polidog\Relayer\Profiler\Profiler`            | Request-tracing facade (dev: recording / prod: no-op).                 |
| `Polidog\Relayer\Profiler\ProfilerWebView`     | `/_profiler` dev view (index + detail).                                |

The only third-party runtime dependency is `polidog/use-php` (the JSX-style
component runtime). DI, dotenv, and Symfony YAML config are all wired by
`Relayer::boot()` — there is no other package to install.

## Running Tests

```bash
vendor/bin/phpunit
```

## License

MIT
