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
- A track's cluster (Star colour) = the dominant cluster of its reason guest.
- Display font: Bricolage Grotesque (variable `opsz`/`wdth`/`wght`, OFL) served locally from `resources/fonts/bricolage-grotesque` via the Vite fonts `local()` provider; latin-ext is a second family ("Bricolage Grotesque Ext") in the `--rt-font` stack, because `local()` has no unicode-range. Instrument Sans (kit default, auth/settings pages) comes from Bunny at build time. The now-playing title's width and weight follow the energy level.
- Host screen layout: `room/stage.ts` picks TV (landscape ≥ 1024px, one 1920×1080 stage scaled to fit) or phone. Pool coordinates are layout px; the 3D world is 1 unit = 100 layout px, origin at the frame centre, so the HUD and the islands line up. Design tokens live in `resources/css/tokens.css`; genre colours are read from them in `room/genres.ts`.
- A cluster's `position` x/z is its preferred spot on the host screen floor (x −7.2…7.2, z −3.05…3.05); `layoutIslands()` maps it into the pool band and pushes overlaps apart. y is unused.
- The canvas is transparent over the CSS space. Island haze is a blurred div in a DOM layer behind the canvas (`hazePortal`), and bloom outputs alpha 0 (`LightOnlyBloom`); stock bloom's alpha dims whatever is behind a transparent canvas.
- In-scene labels (drei `<Html>`) must use `portal={labelPortal}` from `room/labels.ts`; without a fixed portal the first label can render empty under React 19.
- `three` is pinned to 0.182.x: R3F 9.8 still creates a `THREE.Clock`, which logs a deprecation warning on three ≥ 0.183. Bump when R3F moves to `THREE.Timer`.
- `room/Effects.tsx` falls back to 8-bit buffers on software renderers (SwiftShader/llvmpipe, i.e. hardware acceleration off), where HDR bloom renders black.
- Dev keys on the host screen: G add fake guest, N next track (played track loops to the end of the queue until the server owns the set), E cycle energy, H toggle the shortcut hint.

## YouTube notes (verified on Google's docs and console, Oct 2026)

- Client: `app/Services/YouTube/`: `YouTubeClient` (search, playable) and `TrackResolver` (picked title, then the artist's next song). Config: `YOUTUBE_API_KEY`.
- Quota: `search.list` has its own bucket of 100 calls/day (about 90 new tracks a day); `videos.list` costs 1 of 10,000 units. Every lookup, found or not, is stored on the track (`youtube_checked_at`) so no track is searched twice. Never add a search to a hot path.
- Policy: stored API data must be refreshed or deleted within 30 days (`roomtone:refresh-videos`, scheduled daily, uses `videos.list` only). The player must stay visible and at least 200×200 px, with nothing drawn over it: no audio-only or hidden player. `room/VideoTile.tsx` places it (TV bottom centre in real px, phone over the pool).
- Unmuted playback starts only inside a click ("Start the party", or the phone's play button), via `startPlayback()`.
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
