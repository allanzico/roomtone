#!/usr/bin/env bash
# Smoke test of the Docker image: builds it and runs it the way Render does (one container, PORT=10000, the
# render.yaml settings, a volume for the disk), then checks it end to end. Needs no API keys. Everything it
# creates is removed on exit.
#   docker/smoke.sh [host port]
set -euo pipefail
cd "$(dirname "$0")/.."

port=${1:-18080}
base="http://127.0.0.1:${port}"
key=roomtone
name=roomtone-smoke
work=$(mktemp -d)
cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		echo "--- container logs" >&2
		docker logs --tail 40 "$name" >&2 || true
	fi
	docker rm -f "$name" >/dev/null 2>&1 || true
	docker volume rm "$name" >/dev/null 2>&1 || true
	docker image rm roomtone:smoke >/dev/null 2>&1 || true
	rm -rf "$work"
	exit "$status"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok - $*"; }
run() { docker exec "$name" "$@"; }

docker build -q -f docker/Dockerfile --build-arg REVERB_APP_KEY=$key -t roomtone:smoke . >/dev/null
docker run -d --name "$name" -p "127.0.0.1:${port}:10000" -v "$name:/var/roomtone" \
	-e PORT=10000 -e APP_ENV=production -e APP_DEBUG=false -e APP_URL="$base" \
	-e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" -e TRUSTED_PROXIES='*' \
	-e LOG_CHANNEL=stderr -e DB_CONNECTION=sqlite -e DB_DATABASE=/var/roomtone/database.sqlite \
	-e SESSION_DRIVER=database -e CACHE_STORE=database -e QUEUE_CONNECTION=database \
	-e BROADCAST_CONNECTION=reverb -e INERTIA_SSR_ENABLED=false \
	-e REVERB_APP_ID=roomtone -e REVERB_APP_KEY=$key -e REVERB_APP_SECRET=smoke-secret \
	-e REVERB_HOST=127.0.0.1 -e REVERB_PORT=8080 -e REVERB_SCHEME=http \
	roomtone:smoke >/dev/null
for _ in $(seq 60); do
	[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/up" || true)" = 200 ] && break
	sleep 1
done
pass "built and started on \$PORT"
for p in "queue:work" "reverb:start" "schedule:work"; do
	run ps -o args | grep -q "artisan $p" || fail "$p is not running"
done
pass "queue worker, Reverb and scheduler running"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/up")" = 200 ] || fail "/up"
# SSR is off, so the page is Inertia's JSON plus the built bundle.
home=$(curl -fsS "$base/")
grep -q '"component":"welcome"' <<<"$home" || fail "welcome page"
app=$(grep -o '/build/assets/app-[^"]*\.js' <<<"$home" | head -1)
[ -n "$app" ] && curl -fsS -o /dev/null "$base$app" || fail "app bundle"
page=$(run sh -c 'ls public/build/assets | grep "^welcome-.*\.js$" | head -1')
curl -fsS "$base/build/assets/$page" | grep -q "Start your room" || fail "welcome page bundle"
curl -fsSI "$base/" | grep -qi "x-content-type-options: nosniff" || fail "security headers"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/new")" = 200 ] || fail "/new"
pass "pages and headers"

# A room to join: created straight in the database (no API calls).
run php artisan tinker --execute="App\\Models\\Event::create(['code' => 'SMOKE1', 'name' => 'Smoke', 'host_token' => 'smoke-key']);" >/dev/null
jar="$work/cookies"
curl -fsS -c "$jar" -b "$jar" -o /dev/null "$base/"
xsrf() { python3 -c "import sys,urllib.parse;print(urllib.parse.unquote([l.split()[-1] for l in open(sys.argv[1]) if 'XSRF-TOKEN' in l][0]))" "$jar"; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/e/SMOKE1/host?key=smoke-key")" = 200 ] || fail "host screen"
pass "a room's host screen opens"

# Listen on the room's channel through the web server (websocket) while a guest joins.
listener=""
if command -v node >/dev/null 2>&1; then
	node -e '
		const ws = new WebSocket(process.argv[1]);
		ws.onmessage = (m) => {
			const msg = JSON.parse(m.data);
			if (msg.event === "pusher:connection_established") ws.send(JSON.stringify({ event: "pusher:subscribe", data: { channel: "room.SMOKE1" } }));
			if (msg.event.endsWith("GuestJoined")) { console.log(msg.data); process.exit(0); }
		};
		setTimeout(() => process.exit(1), 20000);
	' "ws://127.0.0.1:${port}/app/${key}?protocol=7&client=smoke&version=1" >"$work/ws" 2>&1 &
	listener=$!
	sleep 2
fi

curl -fsS -c "$jar" -b "$jar" -o /dev/null "$base/e/SMOKE1"
me=$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -H "X-XSRF-TOKEN: $(xsrf)" \
	--data-urlencode 'name=Smoke Sam' --data-urlencode 'loves[]=Ozark' --data-urlencode 'loves[]=Lisbon' --data-urlencode 'loves[]=Tems' "$base/e/SMOKE1/guests")
[ "$me" = "$base/e/SMOKE1/me" ] || fail "join redirected to '$me'"
curl -fsS -b "$jar" "$base/e/SMOKE1/me" | grep -q "Smoke Sam" || fail "guest page"
pass "a guest joins and sees their page"

if [ -n "$listener" ]; then
	wait "$listener" || fail "no GuestJoined over the websocket: $(cat "$work/ws")"
	grep -q "Smoke Sam" "$work/ws" || fail "GuestJoined without the guest"
	pass "GuestJoined arrived over the websocket through the web server"
else
	echo "skip - websocket check (no node)"
fi

run php artisan roomtone:backup >/dev/null
[ -n "$(run ls /var/roomtone/backups)" ] || fail "backup"
pass "backup written"

echo "smoke test passed"
