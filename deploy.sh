#!/bin/sh
# Deploy or update Roomtone in production (compose.prod.yaml): check the settings, pull, build an image tagged
# with the commit, start it, wait for health, prune old images. See docs/DEPLOY.md.
set -eu
cd "$(dirname "$0")"

ENV_FILE=.env.production
compose() { docker compose -f compose.prod.yaml --env-file "$ENV_FILE" "$@"; }
fail() { echo "deploy: $*" >&2; exit 1; }

# One deploy at a time (a second one would race the migrations and the containers).
LOCK=.deploy.lock
mkdir "$LOCK" 2>/dev/null || fail "another deploy is running (remove $LOCK if it is not)"
trap 'rmdir "$LOCK"' EXIT

# ─── Settings check ───────────────────────────────────────────────────────────
[ -f "$ENV_FILE" ] || fail "missing $ENV_FILE (copy .env.production.example and fill it in)"
value() { grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
for key in APP_KEY APP_URL SERVER_NAME REVERB_APP_KEY REVERB_APP_SECRET REVERB_PUBLIC_HOST QLOO_API_KEY AI_PROVIDER_KEY YOUTUBE_API_KEY; do
    [ -n "$(value "$key")" ] || fail "$key is empty in $ENV_FILE"
done
case "$(value APP_URL)" in https://*) ;; *) fail "APP_URL must start with https:// in production" ;; esac
host=$(value APP_URL | sed -e 's#^https://##' -e 's#/.*##')
[ "$host" = "$(value SERVER_NAME)" ] || fail "SERVER_NAME ($(value SERVER_NAME)) does not match APP_URL's host ($host)"
[ "$host" = "$(value REVERB_PUBLIC_HOST)" ] || fail "REVERB_PUBLIC_HOST ($(value REVERB_PUBLIC_HOST)) does not match APP_URL's host ($host)"
if [ "$(value ROOMTONE_TLS)" = origin ]; then
    [ -f docker/certs/origin.pem ] && [ -f docker/certs/origin.key ] || fail "ROOMTONE_TLS=origin needs docker/certs/origin.pem and origin.key"
fi

# ─── Build and start ──────────────────────────────────────────────────────────
if [ -d .git ]; then git pull --ff-only; fi
APP_VERSION=$(git rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)
export APP_VERSION
echo "Deploying Roomtone $APP_VERSION"

compose build
if ! compose up -d --remove-orphans --wait --wait-timeout 300; then
    echo "deploy: the stack did not become healthy. Web logs:" >&2
    compose logs --tail=80 web >&2
    exit 1
fi
compose ps

# Keep the image that is running and the one before it; drop older Roomtone images.
docker images roomtone --format '{{.Tag}}' | grep -v -E "^($APP_VERSION|local)$" | tail -n +2 \
    | while read -r tag; do docker image rm "roomtone:$tag" >/dev/null 2>&1 || true; done
docker image prune -f >/dev/null

echo "Roomtone $APP_VERSION is up at $(value APP_URL)"
