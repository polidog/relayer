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
