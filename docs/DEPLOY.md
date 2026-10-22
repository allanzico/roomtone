# Deploying Roomtone

Roomtone runs as one Docker image in four services, with SQLite on a Docker volume:

| Service | What it runs |
|---|---|
| `web` | FrankenPHP (PHP with Caddy built in): the site, and a proxy for Reverb's websockets. Runs the migrations on start. |
| `queue` | `php artisan queue:work`: guests' loves, set builds, host requests (Qloo, Anthropic and YouTube calls). |
| `reverb` | `php artisan reverb:start`: realtime updates, reached by browsers through Cloudflare and Caddy at `wss://roomtone.stackyard.io/app/…`. |
| `scheduler` | `php artisan schedule:work`: daily YouTube refresh, demo-copy pruning, and a database backup at 03:30 (the newest 7 are kept in `backups/` on the `roomtone` volume). |

## How traffic reaches it

```
browser ──HTTPS / wss──▶ Cloudflare (proxied, SSL mode "Full") ──HTTPS──▶ Caddy on the server ──▶ Laravel / Reverb
```

- Cloudflare handles the public certificate. Caddy never asks Let's Encrypt (`local_certs` in `docker/Caddyfile`): it signs `roomtone.stackyard.io` with its own certificate, which Cloudflare's **Full** mode accepts.
- Every request reaches Laravel from a Cloudflare address, so `TRUSTED_PROXIES=*` makes Laravel read the visitor's real IP and scheme from Cloudflare's forwarded headers (the rate limits on setup, joins and requests depend on it). Allow ports 80/443 only from Cloudflare's IP ranges if you can, so nobody bypasses Cloudflare and forges those headers.
- Websockets pass through Cloudflare unchanged; nothing to switch on.

## What you need

- A Linux server with Docker and the Compose plugin (1–2 GB of RAM is plenty), ports 80 and 443 open.
- In Cloudflare (stackyard.io): an **A record** `roomtone` → the server's IP, **proxied** (orange cloud), and SSL/TLS mode **Full**.
- The API keys: Qloo (hackathon server), Anthropic, YouTube Data API v3.

## First deploy

```bash
git clone <this repository> roomtone && cd roomtone
cp .env.production.example .env.production
```

Fill in `.env.production`:

- `SERVER_NAME=roomtone.stackyard.io`, `APP_URL=https://roomtone.stackyard.io`, `TRUSTED_PROXIES=*` (already the example's values).
- `REVERB_PUBLIC_HOST=roomtone.stackyard.io`, `REVERB_PUBLIC_PORT=443`, `REVERB_PUBLIC_SCHEME=https`: where browsers reach Reverb (built into the JavaScript, so rebuild after changing them).
- `APP_KEY`: `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` (or `php artisan key:generate --show` in any Laravel checkout).
- `QLOO_API_KEY`, `AI_PROVIDER_KEY`, `YOUTUBE_API_KEY`.
- `REVERB_APP_KEY`, `REVERB_APP_SECRET`: any random strings. The key is built into the JavaScript, so rebuild after changing it.

Then:

```bash
./deploy.sh
docker compose --env-file .env.production run --rm web php artisan roomtone:demo:build
```

`roomtone:demo:build` builds the demo party from live Qloo, Anthropic and YouTube calls into this server's database (never into the repository: Qloo's terms). It keeps the existing demo if anything falls short, so it is safe to re-run. It needs about 20 YouTube searches of the daily 100.

## Updating

```bash
./deploy.sh
```

Pulls, rebuilds, restarts, and waits for the health check.

## Useful commands

```bash
docker compose --env-file .env.production logs -f queue          # watch the DJ work
docker compose --env-file .env.production run --rm web php artisan roomtone:backup
docker compose --env-file .env.production exec web php artisan tinker
```

## Trying it on your own machine

Plain HTTP, no certificate. In `.env.production` set `APP_URL=http://localhost`, `SERVER_NAME=http://localhost`, `SESSION_SECURE_COOKIE=false`, `TRUSTED_PROXIES=` (empty), `REVERB_PUBLIC_HOST=localhost`, `REVERB_PUBLIC_PORT=80`, `REVERB_PUBLIC_SCHEME=http`, then:

```bash
docker compose --env-file .env.production up -d --build
docker compose --env-file .env.production exec web php artisan roomtone:demo:build    # or, without API quota: db:seed --class=DemoPartySeeder --force
```

Open http://localhost. Stop with `docker compose --env-file .env.production down` (add `-v` to delete the database volume too).

The database starts empty: nothing is seeded, so `/e/DEMO` is a 404 until the demo is built. Joining from a phone needs the public deploy (the QR points at `APP_URL`).
