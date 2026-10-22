#!/bin/sh
# Every Roomtone container starts here: make sure the SQLite file exists on the data volume and cache Laravel's
# config, routes and views for this container (config caching reads the environment, so it runs at start, not at
# build time). Then run the service's own command.
set -e

DB="${DB_DATABASE:-/var/roomtone/database.sqlite}"
mkdir -p "$(dirname "$DB")"
[ -f "$DB" ] || touch "$DB"

php artisan optimize --quiet

exec "$@"
