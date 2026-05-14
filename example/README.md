# Relayer example

A tiny app that exercises the main Relayer features:

- Function-style page receiving services via DI — `src/App/page.psx`
- Minimal function-style page (no DI) — `src/App/about/page.psx`
- Class-style page with `#[Cache]` — `src/App/users/page.psx`
- Dynamic route segment with constructor injection — `src/App/users/[id]/page.psx`
- Function-style page using `$ctx->action()` for server-side validation —
  `src/App/signup/page.psx`
- Root layout — `src/App/layout.psx`
- 404 error page — `src/App/error.psx`

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
  .env                  APP_ENV=dev (enables auto PSX compilation)
  composer.json         path repo → ../ (this checkout)
  config/
    services.yaml       Symfony DI registrations
  public/
    index.php           single entrypoint: Relayer::boot()->run()
  src/
    Service/            application services injected into pages
    App/                file-based routes (Next.js App Router-style)
```

## Production

`APP_ENV=dev` enables on-the-fly PSX → PHP compilation. For deploys
unset (or change) `APP_ENV` and pre-compile once:

```bash
vendor/bin/usephp compile src/App
```
