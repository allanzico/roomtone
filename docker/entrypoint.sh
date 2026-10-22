#!/bin/sh
# Every Roomtone container starts here, as www-data. Volumes are only seeded from the image when first created,
# so make sure the directories the app writes to exist, then cache Laravel's config, routes and views for this
# container (config caching reads the environment, so it runs at start, not at build time). Then run the
# service's own command.
set -e

DB="${DB_DATABASE:-/var/roomtone/database.sqlite}"
mkdir -p "$(dirname "$DB")" \
    /app/storage/caddy/data /app/storage/caddy/config \
    /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs
[ -f "$DB" ] || touch "$DB"

php artisan optimize --quiet

exec "$@"
