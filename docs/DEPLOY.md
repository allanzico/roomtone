# Deploying Roomtone

Roomtone runs as one Docker image in four services, with SQLite on a Docker volume:

| Service | What it runs |
|---|---|
| `web` | FrankenPHP (PHP with Caddy built in): the site over HTTPS, and a proxy for Reverb's websockets. Runs the migrations on start. |
| `queue` | `php artisan queue:work`: guests' loves, set builds, host requests (Qloo, Anthropic and YouTube calls). |
| `reverb` | `php artisan reverb:start`: realtime updates, reached by browsers through Caddy at `wss://<your domain>/app/…`. |
| `scheduler` | `php artisan schedule:work`: daily YouTube refresh, demo-copy pruning, and a database backup at 03:30 (the newest 7 are kept in `backups/` on the `roomtone` volume). |

## What you need

- A Linux server with Docker and the Compose plugin (1–2 GB of RAM is plenty), ports 80 and 443 open.
- A domain (or subdomain) whose DNS A record points at the server. Caddy gets the HTTPS certificate on first start.
- The API keys: Qloo (hackathon server), Anthropic, YouTube Data API v3.

## First deploy

```bash
git clone <this repository> roomtone && cd roomtone
cp .env.production.example .env.production
```

Fill in `.env.production`:

- `SERVER_NAME` and `APP_URL`: your domain (`roomtone.example.com` and `https://roomtone.example.com`).
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

Set `SERVER_NAME=localhost` and `APP_URL=https://localhost` in `.env.production`, then:

```bash
docker compose --env-file .env.production up -d --build
docker compose --env-file .env.production exec web php artisan roomtone:demo:build    # or, without API quota: db:seed --class=DemoPartySeeder --force
```

Open https://localhost. Caddy signs `localhost` with its own local certificate, so the browser warns once. Stop with `docker compose --env-file .env.production down` (add `-v` to delete the database volume too).

The production database starts empty: nothing is seeded, so `/e/DEMO` is a 404 until the demo is built.
