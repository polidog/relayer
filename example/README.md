# Relayer example

A tiny app that exercises the main Relayer features:

- Function-style page receiving services via DI — `src/Pages/page.psx`
- Minimal function-style page (no DI) — `src/Pages/about/page.psx`
- Function-style page using `useState` for stateful UI — `src/Pages/counter/page.psx`
- Class-style page with `#[Cache]`, backed by the SQLite `Database` layer —
  `src/Pages/users/page.psx` + `src/Service/UserRepository.php`
- Dynamic route segment with constructor injection, DB lookup by id —
  `src/Pages/users/[id]/page.psx`
- JSON API route (method-keyed map, DI-autowired, no HTML) —
  `src/Pages/api/users/route.php` (`GET /api/users`)
- Dynamic JSON API route with a 404 status escape hatch —
  `src/Pages/api/users/[id]/route.php` (`GET /api/users/1`)
- Function-style page using `$ctx->action()` for server-side validation —
  `src/Pages/signup/page.psx`
- Root layout — `src/Pages/layout.psx`
- 404 error page — `src/Pages/error.psx`

`composer.json` wires `polidog/relayer` through a local path repository
(`../`) so the example always runs against the working copy in this repo.

## Run

```bash
cd example
composer install
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>.

## Layout

```
example/
  .env                  APP_ENV=dev + DATABASE_DSN (SQLite, auto-wires Db)
  composer.json         path repo → ../ (this checkout)
  config/
    services.yaml       Symfony DI registrations
  public/
    index.php           single entrypoint: Relayer::boot()->run()
  src/
    Service/            application services injected into pages
    Pages/              file-based routes (Next.js App Router-style)
```

## Production

`APP_ENV=dev` enables on-the-fly PSX → PHP compilation. For deploys
unset (or change) `APP_ENV` and pre-compile once:

```bash
vendor/bin/usephp compile src/Pages
```
