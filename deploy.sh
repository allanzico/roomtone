#!/bin/sh
# Deploy or update Roomtone on a server with Docker: pull, build, restart, check health. See docs/DEPLOY.md.
set -e
cd "$(dirname "$0")"

[ -f .env.production ] || { echo "Missing .env.production (copy .env.production.example and fill it in)." >&2; exit 1; }

git pull --ff-only
docker compose --env-file .env.production build
docker compose --env-file .env.production up -d --remove-orphans

echo "Waiting for the web service to be healthy…"
for _ in $(seq 1 60); do
    status=$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose --env-file .env.production ps -q web)" 2>/dev/null || true)
    [ "$status" = "healthy" ] && { echo "Roomtone is up."; docker compose --env-file .env.production ps; exit 0; }
    sleep 2
done

echo "The web service did not become healthy. Logs:" >&2
docker compose --env-file .env.production logs --tail=80 web >&2
exit 1
