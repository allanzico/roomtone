#!/bin/sh
# Starts all of Roomtone in one container: SQLite lives on one disk, and on Render only one service can use it.
# Caches config (it reads the environment, so at start, not build), migrates, keeps the queue worker, Reverb and
# the scheduler running in the background, then runs the web server.
set -e

DB="${DB_DATABASE:-/var/roomtone/database.sqlite}"
mkdir -p "$(dirname "$DB")"
[ -f "$DB" ] || touch "$DB"

php artisan optimize --quiet
php artisan migrate --force

# Restarts a command whenever it exits (the worker also stops itself every hour to stay fresh).
keep() { while true; do "$@" || true; sleep 1; done; }
keep php artisan queue:work --sleep=1 --tries=3 --max-time=3600 &
keep php artisan reverb:start --host=127.0.0.1 --port=8080 &
keep php artisan schedule:work &

exec frankenphp run --config /etc/frankenphp/Caddyfile
