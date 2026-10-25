# syntax=docker/dockerfile:1
# Roomtone in one image and one container: web (FrankenPHP = PHP + Caddy), queue worker, Reverb and scheduler.
# Used by compose.yaml locally and by render.yaml on Render.
#   build    Composer packages and the front end (Vite runs `php artisan` for Wayfinder, so PHP and Node); not shipped
#   runtime  the app, vendor and built assets only

FROM dunglas/frankenphp:1-php8.4-alpine AS php-base
RUN install-php-extensions pdo_sqlite intl bcmath pcntl zip opcache
# The base image gives the frankenphp binary a file capability (cap_net_bind_service) so a non-root user can bind
# port 80. Render starts containers without that capability, and the kernel then refuses to run the binary at all
# ("exec: frankenphp: Operation not permitted", exit 126). Render's PORT is above 1024, so it is not needed.
RUN setcap -r /usr/local/bin/frankenphp
WORKDIR /app

FROM php-base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache nodejs npm

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

# Reverb's app key is public (browsers send it); the socket goes to the page's own address.
ARG REVERB_APP_KEY
RUN VITE_APP_NAME=Roomtone VITE_REVERB_APP_KEY="$REVERB_APP_KEY" npm run build \
    && rm -rf node_modules resources/js resources/css resources/fonts tests docs \
        package.json package-lock.json vite.config.ts tsconfig.json eslint.config.js .prettierrc .prettierignore \
        components.json phpunit.xml

FROM php-base AS runtime
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-roomtone.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/start.sh /usr/local/bin/roomtone-start
COPY --from=build /app /app

EXPOSE 80
CMD ["roomtone-start"]
