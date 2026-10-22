# syntax=docker/dockerfile:1

# Roomtone: one image for every service (web, queue, reverb, scheduler). FrankenPHP is PHP with Caddy built in,
# so the web container also does HTTPS.

FROM dunglas/frankenphp:1-php8.4 AS base
RUN install-php-extensions pdo_sqlite intl bcmath pcntl zip opcache
WORKDIR /app

# Build: Composer packages and the front end. The Vite build runs `php artisan` (Wayfinder route types), so this
# stage has both PHP and Node.
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# The browser connects to Reverb through the public host, so these are baked into the JavaScript at build time.
ARG VITE_APP_NAME=Roomtone
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
RUN npm run build && rm -rf node_modules

# Runtime: no Node, no Composer.
FROM base AS app
COPY --from=build /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/roomtone.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/roomtone-entrypoint
RUN mkdir -p /var/roomtone storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

ENTRYPOINT ["roomtone-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
