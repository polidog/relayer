# Relayer example

A tiny app that exercises the main Relayer features:

- Function-style page receiving services via DI — `src/app/page.psx`
- Minimal function-style page (no DI) — `src/app/about/page.psx`
- Class-style page with `#[Cache]` — `src/app/users/page.psx`
- Dynamic route segment with constructor injection — `src/app/users/[id]/page.psx`
- Root layout — `src/app/layout.psx`
- 404 error page — `src/app/error.psx`

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
    app/                file-based routes (Next.js App Router-style)
```

## Production

`APP_ENV=dev` enables on-the-fly PSX → PHP compilation. For deploys
unset (or change) `APP_ENV` and pre-compile once:

```bash
vendor/bin/usephp compile src/app
```
