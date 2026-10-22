# Running Roomtone with Docker

One image runs every part of Roomtone. Local and production are **separate compose projects** with their own volumes and env files, so a local run can never touch production data.

| | Local | Production |
|---|---|---|
| Compose file | `compose.local.yaml` (project `roomtone-local`) | `compose.prod.yaml` (project `roomtone`) |
| Settings | `.env.docker` (from `.env.docker.example`) | `.env.production` (from `.env.production.example`) |
| Address | http://localhost:8088 (plain HTTP) | https://roomtone.stackyard.io (Cloudflare in front) |
| Start | `make local-up` | `./deploy.sh` (or `make deploy`) |

Both env files are git-ignored. Plain `composer run dev` (no Docker) still works for everyday development.

## What runs

| Service | What it runs |
|---|---|
| `web` | FrankenPHP (PHP with Caddy built in): the site, static files, and a proxy for Reverb's websockets. Runs the migrations on start. |
| `queue` | `php artisan queue:work`: guests' loves, set builds, host requests (Qloo, Anthropic and YouTube calls). |
| `reverb` | `php artisan reverb:start`: realtime updates, reached by browsers through Caddy at `/app/…`. |
| `scheduler` | `php artisan schedule:work`: daily YouTube refresh, demo-copy pruning, and a database backup at 03:30 (the newest 7 are kept in `backups/` next to the database). |

The image is built on FrankenPHP's Alpine variant and ships only the app, its Composer packages and the built assets: no Node, no Composer, no front-end sources. Every container runs as `www-data` with all Linux capabilities dropped and `no-new-privileges`; Caddy listens on 8080/8443 inside the container (Docker maps the host's 80/443), so no privilege is needed to bind ports. Logs rotate at 10 MB × 3 per container.

## Local

```bash
cp .env.docker.example .env.docker      # set APP_KEY; API keys are optional
make local-up                           # build and start, waits until healthy
make local-demo                         # the fake demo party (no API calls)
```

Open http://localhost:8088. `make local-logs`, `make local-shell`, `make local-down` (keeps data), `make local-reset` (deletes it).

## Production: roomtone.stackyard.io

### How traffic reaches it

```
browser ──HTTPS / wss──▶ Cloudflare (proxied, SSL mode "Full") ──HTTPS──▶ Caddy ──▶ Laravel / Reverb
```

- Cloudflare holds the public certificate. Caddy never asks Let's Encrypt: with `ROOMTONE_TLS=internal` it signs `roomtone.stackyard.io` with its own certificate, which Cloudflare's **Full** mode accepts. Other modes (one setting, see `docker/Caddyfile`): `origin` for a Cloudflare Origin Certificate in `docker/certs/` (SSL mode **Full (strict)**), `auto` for Let's Encrypt without Cloudflare, `none` for plain HTTP.
- Every request reaches Laravel from a Cloudflare address, so `TRUSTED_PROXIES=*` makes Laravel read the visitor's real IP and scheme from Cloudflare's forwarded headers (the rate limits on setup, joins and requests depend on it). Allow ports 80/443 only from Cloudflare's IP ranges if you can, so nobody bypasses Cloudflare and forges those headers.
- Websockets pass through Cloudflare unchanged.

### What you need

- A Linux server with Docker and the Compose plugin (1–2 GB of RAM is plenty), ports 80 and 443 open.
- In Cloudflare (stackyard.io): an **A record** `roomtone` → the server's IP, **proxied** (orange cloud), SSL/TLS mode **Full**.
- The API keys: Qloo (hackathon server), Anthropic, YouTube Data API v3.

### First deploy

```bash
git clone <this repository> roomtone && cd roomtone
cp .env.production.example .env.production      # fill in APP_KEY, the API keys, REVERB_APP_KEY/SECRET
./deploy.sh
make prod-demo                                   # build the demo party from live calls
```

`deploy.sh` checks `.env.production` first (required keys, `APP_URL` on HTTPS and matching `SERVER_NAME` and `REVERB_PUBLIC_HOST`), holds a lock so two deploys can't overlap, pulls, builds an image tagged with the commit, starts the stack and waits until it is healthy, then drops old images (the running one and the one before stay).

`make prod-demo` (`roomtone:demo:build`) builds the demo party from live Qloo, Anthropic and YouTube calls into this server's database (never into the repository: Qloo's terms). It keeps the existing demo if anything falls short, so it is safe to re-run; it needs about 20 YouTube searches of the daily 100. The database starts empty: `/e/DEMO` is a 404 until then.

### Updating

```bash
./deploy.sh
```

### Useful commands

```bash
make prod-logs        # all services
make prod-shell       # a shell in the web container
make prod-backup      # a backup now (they also run nightly)
```

## Before deploying: the smoke test

```bash
make smoke
```

Builds the image and starts a throwaway stack on port 18088 (its own project and volumes), then checks: healthy start, non-root user, pages and security headers, a demo copy, a guest joining with the live `GuestJoined` event over the websocket through Caddy, the guest's page, and a backup. Everything it created is removed afterwards. Needs no API keys.
