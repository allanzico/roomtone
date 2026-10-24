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
php artisan migrate --seed
```

The seed creates a demo party with fake data (event code `DEMO`).

### 2. Fill in `.env`

| Key                                                    | What it's for                                                                                         |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| `QLOO_API_KEY`                                         | Turning guests' loves into artists. The key only works against `QLOO_API_URL` (the hackathon server). |
| `AI_PROVIDER_KEY`                                      | Anthropic API key for the DJ agent. `AI_MODEL` stays on Haiku.                                        |
| `YOUTUBE_API_KEY`                                      | YouTube Data API v3 key, to find a video for each track.                                              |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Realtime updates. Any values work locally, e.g. `roomtone`, `local-key`, `local-secret`.              |

Without the API keys the fake-data demo still opens, but new guests aren't read and no set is built.

### 3. Run

```bash
composer run dev
```

This starts everything: the app on http://localhost:8000, the queue worker (guests, set builds and host requests run as queued jobs), Reverb on :8080, logs and Vite.

### 4. Open it

- http://localhost:8000: the welcome page. **Try a demo party** copies the demo into a new party and opens its host screen.
- http://localhost:8000/new: set up your own room by talking to the DJ. It creates the event and opens its host screen.
- http://localhost:8000/e/DEMO/host: the host screen, view-only. Add `?key=` and the event's `host_token` (from the `events` table) to get the **Ask the DJ** box.
- http://localhost:8000/e/DEMO: the guest join page. The QR on the host screen links here. To join from a real phone, run the app on your machine's network address and set `APP_URL` to match.

Sound starts only after a click on **Start the party** (or the play button on a phone), because browsers block autoplay with sound.

Keys on the host screen: `N` plays the next track, `H` shows or hides these hints, `/` asks the DJ (host link only). In dev builds (`composer run dev`) `G` also adds a fake guest and `E` cycles the energy level.

### Optional: live parties

These call Qloo, Anthropic and YouTube for real, and use API quota.

```bash
php artisan roomtone:demo:build                          # rebuild DEMO from database/parties/demo.json
php artisan roomtone:party database/parties/rooftop.json # run a fictional party end to end; prints its code and cost
php artisan roomtone:resolve-videos DEMO                 # find YouTube videos for an event's set
```

YouTube allows about 100 searches a day. Each track is looked up once and remembered.

## Deploy

One Docker container (FrankenPHP: PHP with Caddy) runs the site, the queue worker, Reverb and the scheduler, with SQLite on a disk. `docker compose up` runs it locally; `render.yaml` deploys it to Render. See [docs/DEPLOY.md](docs/DEPLOY.md).

## Tests

```bash
php artisan test
```

## License

MIT, see [LICENSE](LICENSE).
