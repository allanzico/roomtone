# Roomtone

## Product

Roomtone is a shared soundtrack built from what everyone loves — even people who can't name a song. It works for any shared listening occasion: party, long drive, family road trip, dinner, cooking at home, workout. "Room" is the brand word for any of these.

- The **host** sets up by **talking to the DJ** (a chat, not a form), then puts the **host screen** (laptop/TV, connected to the speaker) up in the room. It shows a 3D "room" and plays the set.
- **Guests** scan a QR code, no app, no login, and enter **three things they love** — anything: a show, a food, a place, an artist.
- A **DJ agent** (LLM with tools) uses the **Qloo Taste AI API** to turn those cross-domain tastes into music, finds what the room shares, gives every guest at least one song "for them", and shapes the set to the host's energy plan.
- The host can steer the agent in plain language mid-party ("speeches in 10 min, calm it down", "more Afrobeats").
- Every track carries a reason ("because Maria loves Ozark"), shown as the reason line under the title and as **ripple rings** around that guest's point.
- Guests are **white points**; colour belongs to genres (clusters) only. Each genre is a lava **island** whose area is proportional to its guests (radius ∝ √members).

The 3D host screen is the signature experience: extraordinary, playful, premium — not a generic dashboard.

## Hackathon constraints (Qloo Agentic Hackathon, Devpost)

- Must integrate the Qloo API and be an agentic app (built with an agent framework). Qloo must be the differentiator.
- Must be a live, externally hosted app judges can use end-to-end, alone. → needs a "Try a demo party" mode.
- Public repo with all source + run instructions, and an open-source license (MIT) visible in the repo.
- No demo video required.

## Architecture decisions (do not change without asking)

- **Laravel 13** (PHP 8.3+), **Inertia + React + TypeScript + Tailwind** (official React starter kit).
- **SQLite** (WAL mode). Self-hosted via Docker later. No cloud DB, no Supabase, no Vercel.
- **Laravel queues** (database driver) for all slow work: Qloo calls, agent turns, YouTube lookups.
- **Laravel Reverb** for realtime (added in Phase 2, not now).
- **Laravel AI SDK** (`laravel/ai`) for the DJ agent and its tools (Phase 5, not now).
- **3D:** `three`, `@react-three/fiber`, `@react-three/drei`, `@react-three/postprocessing`, `@react-spring/three`. Client state in **zustand** so live updates move individual objects without re-rendering the whole scene.
- **Playback:** YouTube IFrame Player on the host screen (Phase 4). Never download audio. YouTube audio cannot be analysed cross-origin, so audio reactivity will come from the host device mic (later) with a gentle "breathing" fallback. The scene must look great with zero audio input.
- No user accounts. Events are identified by a short code; host access via a secret host token in the URL (later phase).

## Data model (target; Phase 1 creates only what it needs)

- `events`: id, code (6 chars, unique), name, occasion, energy_plan (json), host_token, timestamps
- `guests`: id, event_id, name, color (hex), timestamps
- `guest_loves`: id, guest_id, raw_text, qloo_entity_id (nullable), qloo_type (nullable), resolved_name (nullable)
- `tracks` (global cache): id, artist, title, youtube_video_id (nullable), bpm (nullable), energy (1–5), qloo_artist_id (nullable); unique(artist, title)
- `set_items`: id, event_id, track_id, position, status (queued|playing|played), reason_guest_id (nullable), reason_text
- `taste_clusters`: id, event_id, label, color, position (json x/y/z) — the agent assigns guests to clusters later

## Phases

1. Scaffold + 3D host room with fake data
   1b. Host screen redesign (docs/design/handoff.md §3), fake data ← **current**
2. Guest join (QR + 3-field form) + Reverb live orb landing
3. Deploy (Docker + Caddy HTTPS) + Qloo service with caching
4. YouTube resolve (cached) + IFrame player
5. DJ agent (Laravel AI SDK) rebalancing the set on guest join
6. Host request box → agent + "why this song" beam wired to real data
7. Demo party mode + energy arc + setup chat (handoff §4)
8. Guest phone polish + mic audio reactivity
9. Hardening
10. README, license, submission

## Design

`docs/design/handoff.md` is the UI source of truth (tokens, layouts, states, motion, accessibility); the images next to it show the target. Where the file and an image disagree, the file wins.

## Working rules

- Keep it simple. No abstractions "for later". Prefer small, surgical changes.
- Don't add dependencies beyond those listed without asking.
- Backend logic gets Pest tests. 3D code doesn't need tests, but must have no console errors.
- TypeScript strict; no `any` in new code.
- At the end of each phase: list what was built, how to see it, and anything that worried you.

## Repo notes (added during Phase 1)

- Starter kit as of Oct 2026: Laravel 13, Inertia v3, React 19, Tailwind 4, Fortify auth, Wayfinder, Vite+ (`vp`) for build/lint/format. `composer run dev` runs `php artisan dev` (server, queue, logs, Vite).
- Tests are Pest 4 (converted from the kit's PHPUnit tests). Run `php artisan test`.
- `HostRoom` is a full-screen Inertia page with no layout: see the `layout` switch in `resources/js/app.tsx`.
- 3D room lives in `resources/js/room/`. `store.ts` (zustand) is the single source of client state; per-frame values (energy smoothing, orb positions) live in `room/live.ts`, outside React, so animation never re-renders components.
- Guest-to-cluster weights live in the `guest_taste_cluster` pivot (`weight` 0–1). This is the one addition to the data model above: the agent writes it later.
- A host request's pick has no love pool: its cluster is the island matching the genre the host asked for (`interpreted.genre`), else none. A track's cluster = its own artist's Qloo genres (from the reason love's pool) matched to the room's island labels (`HostRoomController::clusterOf`, sent as `clusterId`), never the reason guest's island: a song for a hip hop fan is not hip hop by association. No match: no genre (grey dot). Only when an artist has no genre data at all (the fake demo) does it fall back to the guest's main island.
- Display font: Bricolage Grotesque (variable `opsz`/`wdth`/`wght`, OFL) served locally from `resources/fonts/bricolage-grotesque` via the Vite fonts `local()` provider; latin-ext is a second family ("Bricolage Grotesque Ext") in the `--rt-font` stack, because `local()` has no unicode-range. Instrument Sans (kit default, auth/settings pages) comes from Bunny at build time. The now-playing title's width and weight follow the energy level.
- Host screen layout: `room/stage.ts` picks TV (landscape ≥ 1024px, one 1920×1080 stage scaled to fit) or phone. Pool coordinates are layout px; the 3D world is 1 unit = 100 layout px, origin at the frame centre, so the HUD and the islands line up. Design tokens live in `resources/css/tokens.css`; genre colours are read from them in `room/genres.ts`.
- A cluster's `position` x/z is its preferred spot on the host screen floor (x −7.2…7.2, z −3.05…3.05); `layoutIslands()` maps it into the pool band and pushes overlaps apart. y is unused.
- The canvas is transparent over the CSS space. Island haze is a blurred div in a DOM layer behind the canvas (`hazePortal`), and bloom outputs alpha 0 (`LightOnlyBloom`); stock bloom's alpha dims whatever is behind a transparent canvas.
- In-scene labels (drei `<Html>`) must use `portal={labelPortal}` from `room/labels.ts`; without a fixed portal the first label can render empty under React 19.
- `three` is pinned to 0.182.x: R3F 9.8 still creates a `THREE.Clock`, which logs a deprecation warning on three ≥ 0.183. Bump when R3F moves to `THREE.Timer`.
- `room/Effects.tsx` falls back to 8-bit buffers on software renderers (SwiftShader/llvmpipe, i.e. hardware acceleration off), where HDR bloom renders black.
- On the host link `syncFromServer` keeps the screen's own `nowPlaying` (the screen drives playback and tells the server); a reload that lands before the server heard can't put it back on an earlier track. A view-only screen follows the server.
- Dev keys on the host screen: G add fake guest, N next track, E cycle energy, H toggle the shortcut hint, / ask the DJ (host link only). Without the host key a played track loops to the end of the queue; with it the server owns the set.

## YouTube notes (verified on Google's docs and console, Oct 2026)

- Client: `app/Services/YouTube/`: `YouTubeClient` (search, playable) and `TrackResolver` (picked title, then the artist's next song). Config: `YOUTUBE_API_KEY`.
- Quota: `search.list` has its own bucket of 100 calls/day (about 90 new tracks a day); `videos.list` costs 1 of 10,000 units. Every lookup, found or not, is stored on the track (`youtube_checked_at`) so no track is searched twice. Never add a search to a hot path.
- Policy: stored API data must be refreshed or deleted within 30 days (`roomtone:refresh-videos`, scheduled daily, uses `videos.list` only). The player must stay visible and at least 200×200 px, with nothing drawn over it: no audio-only or hidden player. `room/VideoTile.tsx` places it (TV bottom centre in real px, phone over the pool).
- Unmuted playback starts only inside a click ("Start the party", or the phone's play button), via `startPlayback()`. `autoStart()` starts it once without the button when the page already has user activation (`navigator.userActivation.hasBeenActive`: the setup chat's "Open the room" click carries over, as Inertia stays in one document); if the browser still blocks, `onAutoplayBlocked` brings the Start button back.
- A video that won't play (player errors 2, 100, 101, 150) is skipped at once; on the host link the screen posts `POST /e/{code}/unavailable`, which asks YouTube (`videos.list`, 1 quota unit) whether the video is really gone and only then clears the track's `youtube_video_id` (the lookup stays stored, so it is never searched or picked again) and logs "Unavailable YouTube video" (artist, title, video id, error); a video YouTube still calls playable (or when YouTube can't be asked) is kept, so one screen with a blocked embed can't wipe good tracks from every room.
- TV playback controls (`TvControls`: play/pause, next, volume) sit under the tile; the phone has them in its playback bar.
- Volume: `store.volume` (0–100) and `store.muted`, kept in localStorage, applied with the player's `setVolume`/`mute`/`unMute` (also inside the Start click). `VolumeControl` is YouTube-like (mute button with a level icon, slider; dragging to 0 mutes): under the tile on a TV, never over the video, and in the phone's playback bar. YouTube's own controls stay off (`controls: 0`) so its pause can't drift from the room's.
- "End party" (host link only, next to the room name) confirms, `POST /e/{code}/end` sets `events.ended_at`, then goes to `/new`. After that guests' pages say "This party has ended" (`guest/PartyEnded.tsx`, live through `RoomUpdated`), joining, editing loves and host requests are refused, and `ResolveGuest`/`BuildSet`/`SteerSet` return at once: no Qloo or model calls for a finished party. The host link of an ended party shows the same notice.
- The host screen's queue (`HostRoomController`) leaves out tracks with no video, unless nothing in the set has one (then it only shows the set). A playing track with no video is skipped by `VideoTile`. Before this, a videoless tail made `hasVideo` false, which unmounted the player and the TV controls and left a "playing" track nobody could skip.
- The TV join corner has "Copy link" (the guest link; when the clipboard is refused it shows the link to copy by hand). Phones use the Invite button (share sheet, then clipboard).
- `roomtone:resolve-videos {code}` resolves an event's set (the demo party needs it after seeding).

## Qloo API notes (verified live, Oct 2026)

- Client: `app/Services/Qloo/QlooClient.php`: `candidates()` (search), `artists()` (insights), `tags()`. Everything is cached forever in the app cache; failures throw `QlooException` and are never cached. Config: `QLOO_API_KEY`, `QLOO_API_URL` (hackathon server; the key only works there).
- Never commit Qloo response data (Qloo terms): no fixtures or seeds built from real responses. Tests use `Http::fake`.
- `/search` is lexical and mixed-type, and ignores `limit`. It is a list of candidates for the agent to choose from, never an answer: "Alfama" gives Alfamart, "jollof rice" gives Damien Rice.
- Cities (locality/destination) are rejected as signals (400). Pass the city name as `location` instead. Food/dish tags as artist signals are silently ignored; a restaurant entity works as a loose bridge.
- Pooling every guest into one insights call gives a mush skewed to the biggest group. Call per guest and let the agent balance the set.
- Steer with `QlooClient::MOODS` (`style:qloo:calm` / `energetic`) and genre tags from `tags()`. Do not use `genre:music:{upbeat,danceable,chill}`.
- Artists carry `notable_songs` and tags but no BPM, energy or per-result reason. Those come from the agent.
- Insights calls take about 3.5–4.5s cold, so call them from queue jobs, not requests.

## DJ agent design (validated by spikes, Oct 2026)

Read `docs/DJ-agent-handover.md` before building Phases 3-7. Short version: stage 1 per guest (queued job: one Haiku pick call per guest, per-love single-signal Qloo calls, stored pools), stage 2 picks only from stored pools through a validating `add_track` tool, code writes every "because X loves Y" reason, host requests use request pools, global cap of 3 concurrent Qloo calls with 429 retry. Haiku is the model for the demo. Reference scripts: `docs/spikes/` (their outputs hold Qloo data and must stay out of the repo).

## DJ agent as built (Phase 5)

- Laravel AI SDK (`config/ai.php`): Anthropic only; every model slot is `AI_MODEL` (Haiku). The SDK's own default is Sonnet, so never remove that pinning.
- Stage 1: `ResolveGuest` job → `Services/Dj/GuestResolver` → `Ai/Agents/LovePicker` (structured output, no tools). The Qloo searches, then the insights calls, run side by side through `Concurrency` (process driver; tests use `sync` via `phpunit.xml`, so `Http::fake` still applies); stage 1 takes about 9 s. A food or dish with no real candidate is bridged to the city most associated with it ("Rice and beans" → Rio de Janeiro), and the guest page says so ("Got it: Rio de Janeiro"). Loves are cleaned to 80 chars (`GuestResolver::clean`) and sent as labelled untrusted data. Results go on `guest_loves`: `status` (pending|resolved|unresolved) and `pool` (Qloo's 15 ranked artists, kept so sets rebuild and reasons re-verify without Qloo).
- Stage 2: `BuildSet` job (unique per event until it starts; dispatched at once when the room has nothing queued, else 8 s after each resolved guest so joins close together share one build; about 17 s) → `Services/Dj/SetBuilder` → `Ai/Agents/DjAgent` with the `Ai/Tools/AddTrack` tool → `Services/Dj/SetValidator` (every add_track rule; `no_video` only for titles already known to have no video, so the agent never waits on YouTube). After the agent, `SetBuilder::withVideos` looks up every new track at once (`TrackResolver::resolveMany`: parallel `search.list` through `Http::pool`, one `videos.list` per round, the artist's next song in a second round) and drops a track with no video in either. The "DJ set built" log line has `youtube_seconds`, `youtube_lookups` and `model_rounds`. Measured Oct 2026 on a fresh room: build 11 s (YouTube 1 s for 10 tracks, Haiku about 10 s over 2 rounds), was 30 s with lookups one by one; join to first song 22 s. Qloo: search 0.5–0.9 s, insights about 4 s for a new entity. The agent addresses guests and loves by numeric id. Played and playing tracks are kept; the queued part is replaced.
- A love Qloo rejects (a dish matches a dozen restaurants named after it, none with music data) is retried once without that candidate and every other of its type, so the picker lands on the dish's home city instead of the next restaurant.
- A guest's loves take turns (`love_spread`): one love gets another of that guest's own tracks only after their other loves have as many (bridges don't count). Before this, one love could take all of a guest's tracks.
- Reasons are written by `SetValidator`: "because Maria loves Ozark", plus "and Lena loves Dark" when another guest's love ranks the same artist in Qloo's top 5. `set_items.reason_love_id` and `reason_rank` make each one checkable against the stored pool.
- Islands: `Services/Dj/GuestClusterer` (run by `BuildSet` before the set) groups guests by code from Qloo genre tags kept in the pools (`QlooClient` reads only `urn:tag:genre:qloo` and `urn:tag:subgenre:qloo`; `genre:music` mixes in nationalities). Genres everyone shares are discounted (TF-IDF), up to 6 clusters are chosen greedily to cover the room, labels are Qloo's genre names, and clusters are matched by label across rebuilds so ids and colours stay put. Guests whose genres match no island stay in the ember.
- Haiku sends many add_track calls in one response: `DjAgent` needs a high `MaxTokens` (a response cut off at the limit silently drops all its tool calls; `SetBuilder` logs a warning when that happens).
- Try it: `php artisan roomtone:party database/parties/rooftop.json` (fictional guests; live Qloo, Anthropic and YouTube calls; prints time, tokens, cost and 429s). `--stage1-only` for load tests (`database/parties/fifty.json`).

## Host requests and the host link (Phase 6)

- Host link: `/e/{code}/host?key={host_token}` (`roomtone:party` prints the code; the key is `events.host_token`). Without the key the screen is view-only. With it: the "Ask the DJ" composer (phone: above the playback bar; TV: press / or click the hint), and the screen reports every track change (`POST /e/{code}/now-playing`) so the server's set is the truth for rebuilds and requests.
- Requests: `POST /e/{code}/requests` → `SteerSet` job → `Services/Dj/RequestSteerer`: `Ai/Agents/RequestReader` reads genre/mood/summary (genre via `QlooClient::tags()`, first `urn:tag:genre:music:*`; moods via `QlooClient::MOODS`), one Qloo insights call per guest with all their signals plus the tags as a filter, then `Ai/Agents/RequestDj` picks one track per guest (up to 8) through `RequestValidator`. Picks go right after the playing track (`set_items.host_request_id`), survive set rebuilds, and the newest request replaces an older one's queued picks. Requests with no genre or mood change nothing and say so.
- While a request is pending the host screen polls every 3s (Inertia `usePoll`); Reverb will replace this.
- YouTube's daily search quota answers HTTP 429 with a per-day `quota_limit` detail, not only 403 `quotaExceeded`; `YouTubeClient::noteQuota` handles both. When it is out, tracks are accepted without a video and the player skips them.

## Demo party and energy arc (Phase 7)

- Deploy step: `php artisan roomtone:demo:build` builds the demo (event code `DEMO`) from `database/parties/demo.json` (16 fictional guests, plan in minutes from now) with live Qloo, Anthropic and YouTube calls into this server's database. It builds into a temporary event and only replaces `DEMO` if at least 75% of guests have results, there are 10+ tracks and 80% have a video; otherwise (or with a key missing, e.g. after judging) it keeps the existing demo. Never commit its output.
- "Try a demo party" (`POST /demo`, welcome page) copies `DEMO` into a new event (`events.demo = true`, own code and host key) from the database alone, then redirects to its host link. `model:prune` (daily) deletes copies older than a day.
- `Services/Dj/EnergyArc`: target energy per upcoming slot (3.5 min a track) from `events.energy_plan` (clock times, crossing midnight; a default warm/build/peak/wind-down arc without a plan); `SetBuilder` orders each new set to follow it, never the same guest twice in a row. The host screen's energy follows the playing track's energy. Plan times are in the app timezone (UTC) until the setup chat asks the host's.
- Starfield values are rounded: the welcome page is server-rendered and unrounded floats broke hydration.

## Guest join and realtime (Phase 2, built after Phase 7)

- Guests: `/e/{code}` (join: name ≤ 40, exactly three loves ≤ 80, trimmed) → `POST /e/{code}/guests` → `/e/{code}/me` (how each love was read, then their song and how far away it is). A cookie `roomtone_guest_{CODE}` (48 h) remembers the phone; a returning phone goes to its page instead of joining twice. Demo copies can be joined.
- On their page a guest can change their name and loves (`PUT /e/{code}/me`, same `GuestForm` as joining): changed loves are reset to pending in place (ids kept, so played tracks keep their reasons), `ResolveGuest` runs again and the set rebuilds as usual. A love read as a place shows "Got it: music from Rio de Janeiro" (`place`), since a food is bridged to its city's music.
- The host screen's QR is an SVG of the join link drawn server-side with `bacon/bacon-qr-code` (already installed via Fortify). No JS QR library.
- Realtime is Laravel Reverb (`php artisan dev` starts it on :8080; deploy must run `php artisan reverb:start` and proxy websockets). Public channel `room.{CODE}`: `GuestJoined` (the guest, so the point lands at once) and `RoomUpdated` (empty: screens reload their props) after stage 1, set builds and host requests. Tests broadcast to the `null` driver.
- Reverb needs `guzzlehttp/psr7` ^2, so Guzzle is 7.x (not 8). `@babel/core` is pinned as a dev dependency: adding the Echo packages made npm drop it as an auto-installed peer and broke the Vite build.
- `php artisan install:broadcasting` needs a TTY for its npm step and edits files even with `--pretend`; it was finished by hand (see git history).

## Setup chat (Phase 7b)

- `/new` (linked from the welcome page): the host talks to the DJ. Each turn is `POST /new/turn` (JSON, stateless: the page sends the conversation and the draft) → `Ai/Agents/SetupDj` (Haiku, structured output, no tools) → `Services/Dj/SetupDraft` checks every proposal (occasions from a fixed list, times snapped to 15 minutes, energy 1–5, at most 6 points, short labels and name). A failed call answers "I lost the thread. Say that again?" with the draft kept.
- The plan is the design's shape: `{timeline: clock|trip, points, tz, minutes?}`. Clock times are in the host's timezone (the browser's, stored as `tz`); trip times are minutes from when the room opens. `EnergyArc::plan()` reads it and the older list shape. "Open the room" / "Start the drive" (`POST /new`) creates the event and goes to its host link.
- `resources/js/setup/`: `PlanCurve` (SVG; drag or arrow keys, 15-minute and 1–5 steps, never past neighbours; `compact` on phones so it stays tall enough to drag; axis labels drop when they would collide), `time.ts` (clock/trip minute helpers). "I heard" facts (occasion, name, time range or trip length) edit in place; the DJ's "Got it" line is written by code.
- The DJ moves fast: one question per turn at most, a plan by the host's second message (with stated defaults if needed), and drive times estimated by Haiku from named places ("Amsterdam to Groningen is about 2 hours by road"); the host chose estimates over a maps API. Every reply carries `choices` (up to 4 quick replies, checked by `SetupDraft::choices`), shown as taps under the DJ's latest line: answers to its question, or tweaks to the plan ("Calmer", "No peak").
- The quick starts ("A long drive", …) answer their first question in the page, with no model call (`STARTS` in `Setup.tsx`: reply, choices, occasion and timeline); typing exactly the same words does too. Every later turn is a Haiku call (about 4–5 s). The SetupDj prompt uses placeholders ("<from> to <to>", "<name>'s 30th") and tells the model choices are generic: concrete examples in the prompt leaked into every host's quick replies.
- The curve keeps 2–6 points: tap the space between two points to add one; tap a point to select it, then its × (or Delete) removes it. The peak is marked only when one point is strictly highest, so a flat plan has none. Trip axis labels read "45 min", "1 h 15".
- Setup's actions float in a bottom dock (`.rt-setup-dock`: quick replies, the plan with its button, the composer); the chat scrolls above it, padded by the dock's measured height (`--rt-dock-h`). `.rt-setup` undoes `.rt-room`'s fixed, clipped frame. The curve is capped at 20vh, keeping its proportions (`--rt-curve-ratio`) so dragging still maps correctly; under 820px tall the button's caption hides. After each reply the chat follows the DJ's newest line down to the dock.
- Left out on purpose: "Clean versions only" and "Anything to avoid?" (nothing can enforce them; the DJ says so if asked) and the "Speaker + TV" fact.
- Rate limits are named, one counter each (`AppServiceProvider::rateLimits`): setup turns 60/hour and rooms 20/hour per IP, demo copies 10/min per IP, joins 60/min and guest edits 20/min per room+IP (a party is often one Wi-Fi), host requests 10/min and host-screen reports 120/min per room. Never use a plain `throttle:N,M`: its counter is shared by every throttled route for an IP, so a playing host screen once used up the setup chat's allowance (a "429 Too Many Requests" page).

## Deploy (Phase 3)

- Hosting is **Render** (`render.yaml`: one Docker web service, `starter` plan, a 1 GB disk at `/var/roomtone`), with `roomtone.stackyard.io` through Cloudflare. Render serves HTTPS; the container speaks plain HTTP on `$PORT` (80 by default). Locally: `compose.yaml`, which reads the same `.env` as `composer run dev` and overrides what differs inside Docker (URL, database path, Reverb address, logs, SSR); http://localhost. Guide: `docs/DEPLOY.md`. Keep it this simple: no own TLS, no prod compose, no deploy script.
- One container on purpose: SQLite is on one disk and a Render disk belongs to one service. `docker/start.sh` caches config, migrates (Render's pre-deploy command can't see the disk), keeps `queue:work`, `reverb:start` (127.0.0.1:8080) and `schedule:work` running in restart loops, then execs FrankenPHP. `docker/Caddyfile` proxies `/app/*` to Reverb; Reverb's `/apps` publish API stays internal.
- Image: `dunglas/frankenphp:1-php8.4-alpine`, runs as root (the default). The build stage has PHP and Node (Vite runs Wayfinder's `php artisan`) and is never shipped. About 90 MB compressed.
- Render passes service env vars to the build as build args; only `REVERB_APP_KEY` is used (it is public and fixed to `roomtone` in `render.yaml`: generated values are base64 and a `/` would break the socket URL). With no `VITE_REVERB_HOST` (Docker builds) `app.tsx` connects Echo to the page's own host, port and scheme.
- `TRUSTED_PROXIES=*` (config `app.trusted_proxies`, applied in `AppServiceProvider` via `TrustProxies::at`) makes rate limits see real visitor IPs through Render and Cloudflare. Inertia SSR is off in Docker (`INERTIA_SSR_ENABLED=false`), on locally.
- `roomtone:backup` (daily 03:30) writes `VACUUM INTO` copies to `/var/roomtone/backups/` and keeps 7 (don't restore Render disk snapshots of a live SQLite file); its test uses its own SQLite file (VACUUM can't run inside RefreshDatabase's transaction).
- `docker/smoke.sh` runs the image as Render would (`PORT=10000`) and checks it end to end, including a guest join over the websocket. Run it before pushing Docker changes. With SSR off the page text is in the JS chunks, not the HTML.

## Taste test

- `roomtone:taste {file}` is a standalone taste test (Qloo search → Claude picks or bridges → Qloo artists → Claude picks songs) that calls the Messages API directly with the `config/ai.php` key, URL and model. Its people file and report live in `storage/app/taste-test/` (git-ignored: Qloo data).
