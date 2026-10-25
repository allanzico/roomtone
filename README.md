# Roomtone

A party soundtrack built from what guests love, even guests who can't name a song.

Guests scan a QR code and type three things they love: a show, a food, a place, an artist. A DJ agent uses the [Qloo Taste AI API](https://www.qloo.com/) to turn those tastes into a set, and the host screen shows the party as a living 3D room where every song points at the guest it was picked for.

Built for the Qloo Agentic Hackathon.

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+.

### 1. Install

```bash
composer install
npm install
cp .env.example .env        # skip if you already have a .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### 2. Fill in `.env`

| Key                                                    | What it's for                                                                                         |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| `QLOO_API_KEY`                                         | Turning guests' loves into artists. The key only works against `QLOO_API_URL` (the hackathon server). |
| `AI_PROVIDER_KEY`                                      | Anthropic API key for the DJ agent. `AI_MODEL` stays on Haiku.                                        |
| `YOUTUBE_API_KEY`                                      | YouTube Data API v3 key, to find a video for each track.                                              |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Realtime updates. Any values work locally, e.g. `roomtone`, `local-key`, `local-secret`.              |

Without the API keys rooms can be set up, but guests aren't read and no set is built.

### 3. Run

```bash
composer run dev
```

This starts everything: the app on http://localhost:8000, the queue worker (guests, set builds and host requests run as queued jobs), Reverb on :8080, logs and Vite.

### 4. Open it

- http://localhost:8000: the welcome page.
- http://localhost:8000/new: set up your own room by talking to the DJ. It creates the event and opens its host screen.
- http://localhost:8000/e/{code}/host: a room's host screen, view-only. The link `/new` opens has `?key=` with the event's `host_token`, which adds the **Ask the DJ** box.
- http://localhost:8000/e/{code}: the guest join page. The QR on the host screen links here. To join from a real phone, run the app on your machine's network address and set `APP_URL` to match.

Sound starts only after a click on **Start the party** (or the play button on a phone), because browsers block autoplay with sound.

Keys on the host screen: `N` plays the next track, `H` shows or hides these hints, `/` asks the DJ (host link only). In dev builds (`composer run dev`) `G` also adds a fake guest and `E` cycles the energy level.

### Optional: live parties

These call Qloo, Anthropic and YouTube for real, and use API quota.

```bash
php artisan roomtone:party database/parties/rooftop.json # run a fictional party end to end; prints its code and cost
php artisan roomtone:resolve-videos {code}               # find YouTube videos for an event's set
```

YouTube allows about 100 searches a day. Each track is looked up once and remembered.

## Deploy

One Docker container (FrankenPHP: PHP with Caddy) runs the site, the queue worker, Reverb and the scheduler, with SQLite on a disk. `docker compose up -d --build` runs it locally (it reads your `.env`); `make deploy` rebuilds it from scratch.

### On Render

`render.yaml` is a Blueprint for one Docker web service (`starter` plan, a 1 GB disk at `/var/roomtone` for SQLite, health check `/up`, deploys the `main` branch).

1. In Render: **New → Blueprint**, pick this repository. Render reads `render.yaml`.
2. Give it the values it asks for (marked `sync: false`): `APP_KEY` (`php artisan key:generate --show`), `APP_URL` (the public address, e.g. `https://roomtone.stackyard.io`), `QLOO_API_KEY`, `YOUTUBE_API_KEY`, `AI_PROVIDER_KEY`. Everything else is in the file; `REVERB_APP_SECRET` is generated.
3. Optional custom domain: add it on the service, then in Cloudflare a CNAME to the `onrender.com` address with SSL/TLS mode **Full (strict)**. `TRUSTED_PROXIES=*` lets Laravel see visitors' real IPs and HTTPS through the proxies; websockets pass through.

Every push to `main` redeploys. With a disk, Render stops the old container before starting the new one, so a deploy means a few seconds of downtime. Migrations run when the container starts. The container keeps `queue:work`, `reverb:start` and `schedule:work` running; the scheduler refreshes stored YouTube data daily and backs up the database nightly to `/var/roomtone/backups/` (newest 7 kept).

YouTube's search quota (about 100 searches a day) limits how many new rooms can get songs with videos each day: ask Google for more before a busy event.

## Tests

```bash
php artisan test
```

## License

MIT, see [LICENSE](LICENSE).
