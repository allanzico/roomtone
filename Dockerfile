# syntax=docker/dockerfile:1
# =============================================================================
# Roomtone: one image for every service (web, queue, reverb, scheduler).
#   1. php-base  FrankenPHP (PHP 8.4 with Caddy built in) on Alpine, with the extensions the app uses
#   2. build     Composer packages and the front end (Vite runs `php artisan` for Wayfinder's route types,
#                so this stage has PHP and Node); never shipped
#   3. runtime   the app, vendor and built assets only: no Node, no Composer, no front-end sources; runs as
#                www-data and listens on 8080/8443, so it needs no root and no extra capabilities
# Local and production use the same image (compose.local.yaml, compose.prod.yaml).
# =============================================================================

FROM dunglas/frankenphp:1-php8.4-alpine AS php-base
RUN install-php-extensions pdo_sqlite intl bcmath pcntl zip opcache
WORKDIR /app

# ─── Build ───────────────────────────────────────────────────────────────────
FROM php-base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache nodejs npm

# Dependencies first: these layers only rebuild when the lock files change. The cache mounts keep downloads
# between builds on the same machine.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund --prefer-offline

COPY . .
# Secrets come from the environment at run time, never from a file baked into the image.
RUN test ! -e .env
RUN mkdir -p bootstrap/cache \
    && composer dump-autoload --classmap-authoritative --no-dev --no-interaction

# Browsers reach Reverb at the public address, so it is built into the JavaScript (public values, not secrets).
ARG VITE_APP_NAME=Roomtone
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=80
ARG VITE_REVERB_SCHEME=http
RUN npm run build

# What the runtime does not need.
RUN rm -rf node_modules resources/js resources/css resources/fonts tests docs \
    package.json package-lock.json vite.config.ts tsconfig.json eslint.config.js .prettierrc .prettierignore \
    components.json phpunit.xml

# ─── Runtime ─────────────────────────────────────────────────────────────────
FROM php-base AS runtime
ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="Roomtone" \
      org.opencontainers.image.description="A shared soundtrack built from what everyone loves" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${APP_VERSION}"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-roomtone.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/caddy/ /etc/frankenphp/tls/
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/roomtone-entrypoint

# Owned by www-data at build time, so nothing needs root at run time. Caddy keeps its state (certificates)
# under storage/, which is a volume, instead of the image's root-owned /data and /config.
COPY --chown=www-data:www-data --from=build /app /app
RUN mkdir -p /var/roomtone storage/caddy/data storage/caddy/config \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/roomtone storage bootstrap/cache

ENV APP_VERSION=${APP_VERSION} \
    XDG_DATA_HOME=/app/storage/caddy/data \
    XDG_CONFIG_HOME=/app/storage/caddy/config

USER www-data
EXPOSE 8080 8443
ENTRYPOINT ["roomtone-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
