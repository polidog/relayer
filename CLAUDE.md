# CLAUDE.md

Guidance for working in this repository.

## What this is

`polidog/relayer` — an opinionated framework **library** on top of
[`polidog/use-php`](https://github.com/polidog/usePHP): a Next.js App
Router-style file-based router, Symfony DependencyInjection wiring, dotenv,
HTTP cache, session + bearer-token auth, Zod-style validation, and a dev
profiler, all behind a single `Relayer::boot()` entrypoint. This repo *is*
the framework; `example/` is a consumer app used for manual checks.

## Requirements

- PHP **>= 8.5** (pinned to 8.5.2 via `mise.toml`).
- `composer install` to set up. `composer.lock` is **gitignored** (this is a
  library — do not commit it).

## Commands

```bash
composer install                                  # set up
vendor/bin/phpunit                                # full test suite
vendor/bin/phpunit --filter SomeTest              # one test class
vendor/bin/phpunit tests/Path/To/SomeTest.php     # one test file
vendor/bin/phpstan analyse --no-progress          # static analysis (level max)
vendor/bin/php-cs-fixer fix                        # format
vendor/bin/php-cs-fixer fix --dry-run --diff       # check only (CI gate)
```

CI (`.github/workflows/ci.yml`) runs exactly: `phpunit`, `phpstan analyse`,
`php-cs-fixer fix --dry-run`. All three must pass. Match CI locally before
pushing.

The shipped CLI (`bin/relayer`, for *consumers* of the framework):
`relayer init` / `upgrade` / `routes` / `routes:compile` /
`container:compile` / `profiler:clear` — all dispatched through
`Scaffold\InitCommand::run()`.

## Layout

`src/` is PSR-4 `Polidog\Relayer\`, `tests/` is `Polidog\Relayer\Tests\`.

| Namespace            | Responsibility                                              |
|----------------------|-------------------------------------------------------------|
| `Relayer`, `AppConfigurator`, `InjectorContainer` | boot entrypoint, app config hook, PSR-11 adapter |
| `Di`                 | `ContainerFactory` — all DI container wiring                 |
| `Router`             | file-based router, route groups, compiled routes, API route handlers |
| `Http`               | `Request`/`Response`, `#[Cache]`, ETag store, CORS          |
| `Auth`               | session auth (`#[Auth]`, `UserProvider`, hashing)           |
| `Auth\Token`         | bearer-token auth — Firebase/Cognito JWKS verification      |
| `Validation`         | Zod-style schema validator                                  |
| `Profiler`           | dev-only request profiler (`/_profiler`)                    |
| `Db`                 | `Database` contract, PDO impl, caching/traceable decorators |
| `Scaffold`           | `relayer` CLI commands                                       |
| `Psx`, `React`, `Log`| PSX component registrar, React islands, traceable logger    |

## Conventions

- **`declare(strict_types=1);`** in every PHP file. Classes are `final`
  unless designed for extension.
- Formatting is enforced by `.php-cs-fixer.dist.php`: `@PSR12` +
  `@PhpCsFixer` (incl. `:risky`), `native_function_invocation` /
  `native_constant_invocation` (so `\strlen`, `\is_array`, etc. in
  namespaced code), short arrays, trailing commas in multiline, classes
  imported via `use` but functions/constants **not** imported. Don't
  hand-fight the formatter — see the hook below.
- **PHPStan level `max`** over `src` + `tests`, excluding only
  `tests/Router/fixtures`. Keep it green; add precise generics/types
  rather than suppressing.
- cs-fixer scans `src` + `tests`, `*.php` only, skipping any directory
  named `fixtures`. So the router's `tests/**/fixtures` trees (the
  `.psx`/page scaffolds the scanner consumes) are formatted/analyzed by
  neither tool, but `tests/Fixtures/` is **real** `Tests\Fixtures` support
  code and *is* both formatted and analyzed — treat it as production code.
- Tests: PHPUnit 11, `final` classes, `self::assert*`. Use PHPUnit
  **attributes**, not docblock metadata (`@covers`/`@internal` are
  intentionally disabled in cs-fixer).
- `README.md` and `README.ja.md` are kept **in sync** — update both when
  changing documented behavior.
- `.env` uses the Symfony cascade (`.env` committed, `.env.local` /
  `.env.*.local` gitignored).

## Editing PHP — auto-format hook

A `PostToolUse` hook (`.claude/hooks/php-cs-fixer.sh`, wired in
`.claude/settings.json`) runs `php-cs-fixer` on each `.php` file you Edit or
Write under `src/`/`tests/` (fixtures skipped), so the tree stays
CI-clean automatically. It is silent and non-blocking. Consequence: a file
may be reformatted right after your edit — if a follow-up `Edit` fails on a
stale `old_string`, re-`Read` the file and retry.
