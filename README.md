# Relayer

[English](README.md) · [日本語](README.ja.md)

Opinionated, batteries-included framework on top of
[polidog/use-php](https://github.com/polidog/usePHP). Bundles:

- A Next.js App Router-style file-based router (`src/app/page.psx`,
  `layout.psx`, dynamic segments, error pages)
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html)
  for service wiring (autowire, YAML/PHP config auto-load)
- [symfony/dotenv](https://github.com/symfony/dotenv) for `.env` loading
  with the standard `.env` / `.env.local` / `.env.{APP_ENV}` cascade
- `#[Cache]` attribute for HTTP cache headers + `If-None-Match` 304 handling
  with pluggable `EtagStore` (file-based default, Redis-ready)
- Session-based authentication: `#[Auth]` attribute / `$ctx->requireAuth()`,
  role checks, password hashing, pluggable `UserProvider` and `SessionStorage`

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
    app/               # AppRouter file-based routes live here
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
DATABASE_URL=mysql://localhost/app
```

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
`vendor/bin/usephp compile src/app` during deploy.

## Routing & Pages

The router scans `src/app/` and maps the filesystem to URLs in the spirit of
the Next.js App Router. The conventions:

| File                 | Role                                                                |
| -------------------- | ------------------------------------------------------------------- |
| `page.psx`           | Renders the route. One per directory.                               |
| `layout.psx`         | Wraps every nested page; layouts stack from root to leaf.           |
| `error.psx`          | 404 / unmatched-route fallback (root only).                         |
| `[param]/`           | Dynamic segment; captured into `$this->getParam('param')`.          |

`.psx` is the JSX-style source. The runtime executes the compiled
`*.psx.php` sibling — produced automatically in dev (`APP_ENV=dev`) or by
`vendor/bin/usephp compile src/app` at deploy time. Plain `.php` page files
also work and skip the compile step.

### Class-style page

```php
<?php
// src/app/users/[id]/page.psx
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
// src/app/about/page.psx
return fn() => <main><h1>About</h1></main>;
```

Services from the container are resolved by type — `PageContext` is the
per-request handle, every other typed parameter comes from the DI container:

```php
<?php
// src/app/users/page.psx
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
src/app/
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

### Form actions (CSRF-protected)

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

Function-style pages declare server actions through `PageContext::action()`.
The factory closure runs on every request — including the POST that submits
the form — so the action table is rebuilt before dispatch and the token only
needs to carry `(pageId, name)`:

```php
<?php
// src/app/users/page.psx
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
// src/AppConfigurator.php
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
// src/app/users/page.psx
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
// src/app/signup/page.psx
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
// src/app/login/page.psx
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
// src/app/dashboard/page.psx
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
// src/app/page.psx
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
// src/app/feed/page.psx
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

## Source Layout

| Namespace                                              | Purpose                                                                |
| ------------------------------------------------------ | ---------------------------------------------------------------------- |
| `Polidog\Relayer\Relayer`                    | Boot entrypoint (env load + DI build + router wire-up).                |
| `Polidog\Relayer\AppConfigurator`              | Extension point for service registrations.                             |
| `Polidog\Relayer\InjectorContainer`            | PSR-11 adapter with reflection autowire + 304 short-circuit.           |
| `Polidog\Relayer\Router\AppRouter`             | File-based router for `src/app/` (PSR-11 container–driven).            |
| `Polidog\Relayer\Router\Component\*`           | `PageComponent`, `ErrorPageComponent`, `FunctionPage`, `PageContext`.  |
| `Polidog\Relayer\Router\Layout\*`              | `LayoutComponent` + nested layout rendering.                           |
| `Polidog\Relayer\Router\Document\*`            | HTML document wrapper / metadata.                                      |
| `Polidog\Relayer\Router\Form\*`                | CSRF tokens + form action dispatcher.                                  |
| `Polidog\Relayer\Router\Routing\*`             | Page scanner, route table, matcher.                                    |
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

The only third-party runtime dependency is `polidog/use-php` (the JSX-style
component runtime). DI, dotenv, and Symfony YAML config are all wired by
`Relayer::boot()` — there is no other package to install.

## Running Tests

```bash
vendor/bin/phpunit
```

## License

MIT
