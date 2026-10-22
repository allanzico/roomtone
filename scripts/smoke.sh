#!/usr/bin/env bash
# Smoke test of the Docker setup: builds the image, starts a throwaway stack (its own compose project, port and
# volumes, from compose.local.yaml), and checks it end to end. Needs no API keys. Everything it creates is
# removed on exit.
#   scripts/smoke.sh [port]
set -euo pipefail
cd "$(dirname "$0")/.."

port=${1:-18088}
base="http://127.0.0.1:${port}"
work=$(mktemp -d)
compose() { docker compose -p roomtone-smoke -f compose.local.yaml --env-file "$work/env" "$@"; }
cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		echo "--- web logs" >&2
		compose logs --no-color --tail 40 web >&2 || true
	fi
	compose down -v --remove-orphans >/dev/null 2>&1 || true
	docker image rm roomtone:smoke >/dev/null 2>&1 || true
	rm -rf "$work"
	exit "$status"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok - $*"; }

key=$(head -c 10 /dev/urandom | od -An -tx1 | tr -d ' \n')
cat >"$work/env" <<ENV
ROOMTONE_ENV_FILE=$work/env
ROOMTONE_IMAGE_TAG=smoke
LOCAL_PORT=$port
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_URL=$base
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=smoke
REVERB_APP_KEY=$key
REVERB_APP_SECRET=smoke-secret
ENV

compose up -d --build --wait --wait-timeout 300 >/dev/null
pass "built, started and healthy"

[ "$(compose exec -T web id -u)" != 0 ] || fail "web runs as root"
pass "web runs as $(compose exec -T web id -un)"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/up")" = 200 ] || fail "/up"
# SSR is off, so the page is Inertia's JSON plus the built bundle.
home=$(curl -fsS "$base/")
grep -q '"component":"welcome"' <<<"$home" || fail "welcome page"
app=$(grep -o '/build/assets/app-[^"]*\.js' <<<"$home" | head -1)
[ -n "$app" ] && curl -fsS -o /dev/null "$base$app" || fail "app bundle"
page=$(compose exec -T web sh -c 'ls public/build/assets | grep "^welcome-.*\.js$" | head -1')
curl -fsS "$base/build/assets/$page" | grep -q "Try a demo party" || fail "welcome page bundle"
curl -fsSI "$base/" | grep -qi "x-content-type-options: nosniff" || fail "security headers"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/new")" = 200 ] || fail "/new"
pass "pages and headers"

compose exec -T web php artisan db:seed --class=DemoPartySeeder --force >/dev/null
jar="$work/cookies"
curl -fsS -c "$jar" -b "$jar" -o /dev/null "$base/"
xsrf() { python3 -c "import sys,urllib.parse;print(urllib.parse.unquote([l.split()[-1] for l in open(sys.argv[1]) if 'XSRF-TOKEN' in l][0]))" "$jar"; }
copy=$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -H "X-XSRF-TOKEN: $(xsrf)" -X POST "$base/demo")
case "$copy" in "$base"/e/*/host\?key=*) ;; *) fail "demo copy redirected to '$copy'" ;; esac
pass "demo copy opens its host link"

# Listen on the room's channel through Caddy (websocket) while a guest joins.
listener=""
if command -v node >/dev/null 2>&1; then
	node -e '
		const ws = new WebSocket(process.argv[1]);
		ws.onmessage = (m) => {
			const msg = JSON.parse(m.data);
			if (msg.event === "pusher:connection_established") ws.send(JSON.stringify({ event: "pusher:subscribe", data: { channel: "room.DEMO" } }));
			if (msg.event.endsWith("GuestJoined")) { console.log(msg.data); process.exit(0); }
		};
		setTimeout(() => process.exit(1), 20000);
	' "ws://127.0.0.1:${port}/app/${key}?protocol=7&client=smoke&version=1" >"$work/ws" 2>&1 &
	listener=$!
	sleep 2
fi

curl -fsS -c "$jar" -b "$jar" -o /dev/null "$base/e/DEMO"
me=$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -H "X-XSRF-TOKEN: $(xsrf)" \
	--data-urlencode 'name=Smoke Sam' --data-urlencode 'loves[]=Ozark' --data-urlencode 'loves[]=Lisbon' --data-urlencode 'loves[]=Tems' "$base/e/DEMO/guests")
[ "$me" = "$base/e/DEMO/me" ] || fail "join redirected to '$me'"
curl -fsS -b "$jar" "$base/e/DEMO/me" | grep -q "Smoke Sam" || fail "guest page"
pass "a guest joins and sees their page"

if [ -n "$listener" ]; then
	wait "$listener" || fail "no GuestJoined over the websocket: $(cat "$work/ws")"
	grep -q "Smoke Sam" "$work/ws" || fail "GuestJoined without the guest"
	pass "GuestJoined arrived over the websocket through Caddy"
else
	echo "skip - websocket check (no node)"
fi

compose exec -T web php artisan roomtone:backup >/dev/null
[ -n "$(compose exec -T web ls /var/roomtone/backups)" ] || fail "backup"
pass "backup written"

echo "smoke test passed"
