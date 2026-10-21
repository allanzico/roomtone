# Roomtone UI handoff (settled 21 Oct 2026)

The design is settled. This file is the source of truth for the UI; the reference images in this folder show the target. Where this file and an image disagree, this file wins (a few contrast values were raised for accessibility).

| Screen | Image | Lands in |
|---|---|---|
| Host screen, TV / laptop landscape | `host-tv.png` | **Now (Phase 1b)**, fake data |
| Host screen, phone / portrait | `host-phone.png` | **Now (Phase 1b)**, fake data; Pause/Skip wired in Phase 4 |
| Setup, first open (laptop, phone) | `setup-start-laptop.png`, `setup-start-phone.png` | Phase 7 (with the energy arc), DJ replies from the agent |
| Setup, party (laptop, phone) | `setup-party-laptop.png`, `setup-party-phone.png` | Phase 7 |
| Setup, family drive (phone) | `setup-drive-phone.png` | Phase 7 |

Not designed yet: the guest join screen (Phase 2). Build it in this visual language (tokens below, underline inputs, lava CTA) and keep it to: name, three loves, "Join the room".

## 1. Product changes to carry into CLAUDE.md

- Roomtone is for **any shared listening occasion**, not only parties: party, long drive, family road trip, dinner, cooking at home, workout. "Room" stays the brand word for any of these.
- Hosts **set up by talking to the DJ** (a chat), not with a form.
- The "why this song" beam is replaced by **ripple rings** around the reason guest, plus the reason line under the title ("Burna Boy, because **Maria** loves Ozark").
- Guests are **white points**. Colour belongs to genres (clusters) only. `guests.color` stays in the data model but the host screen does not use it.
- Island (cluster) **area is proportional to the number of guests in it**: radius ∝ √members.

## 2. Design tokens

Add these as CSS custom properties in `resources/js/room/room.css` (or a new `resources/css/tokens.css` imported by the room and setup pages). Use the token names in code, not the raw values.

### Colour

| Token | Value | Use |
|---|---|---|
| `--rt-space-0` | `#020203` | Outer background |
| `--rt-space-1` | `#050509` | Mid background |
| `--rt-space-2` | `#0B0B16` | Background glow centre |
| `--rt-bg` | `radial-gradient(ellipse 80% 60% at 55% 40%, var(--rt-space-2) 0%, var(--rt-space-1) 60%, var(--rt-space-0) 100%)` | Every screen |
| `--rt-ink` | `#FFFFFF` | Titles, focus text |
| `--rt-text` | `#EDEDF2` | Body text |
| `--rt-text-2` | `rgba(237,237,242,0.78)` | Secondary lines (reason line) |
| `--rt-text-3` | `rgba(237,237,242,0.60)` | Muted text, user messages |
| `--rt-text-4` | `rgba(237,237,242,0.55)` | Smallest readable text (labels, captions). **Never go fainter for text** (0.45 fails 4.5:1 on `--rt-space-1`) |
| `--rt-line` | `rgba(237,237,242,0.28)` | Input underlines |
| `--rt-hairline` | `rgba(237,237,242,0.12)` | Dividers, dashed guides |
| `--rt-ember` | `#FF8A3D` | Focus colour, input focus underline |
| `--rt-cta` | `linear-gradient(135deg, #FFC08F, #FF8A3D 55%, #D9561C)` | Primary button |
| `--rt-cta-ink` | `#1A0A04` | Primary button text |
| `--rt-cta-glow` | `0 0 40px rgba(255,138,61,0.45)` | Primary button shadow |

### Genre palette (clusters)

Assign in this order as the agent creates clusters; the seeded DEMO clusters already match. Max 6 clusters on screen.

| Slot | Base (dots, bars) | Lava light → dark (island gradient) | Label ink on lava |
|---|---|---|---|
| 1 | `#FF8A3D` | `#FFE0C2` → `#FF8A3D` → `#C7420F` | `#5E1E05` @ 0.7 |
| 2 | `#FF4FD8` | `#FFB3F0` → `#C21FA0` | `#5A0A44` @ 0.7 |
| 3 | `#4C7DFF` | `#B3C8FF` → `#2A4FD0` | `#0E1E5E` @ 0.7 |
| 4 | `#35E8B0` | `#B3FFE4` → `#14A97A` | `#064A34` @ 0.75 |
| 5 | `#FFC08F` | `#FFF0E0` → `#E08A4A` | `#5A2A08` @ 0.7 |
| 6 | `#9D8CFF` | `#DCD4FF` → `#5B47D6` | `#1E1460` @ 0.7 |

### Type

One family: **Bricolage Grotesque** (variable: `opsz` 12–96, `wdth` 75–100, `wght` 200–800, OFL). Files are in `resources/fonts/bricolage-grotesque/`; serve them like Anybody (Vite fonts `local()` provider). It **replaces Anybody** on the host and setup screens; the now-playing title keeps its "width and weight follow energy" behaviour on the `wdth`/`wght` axes. Remove Anybody once nothing uses it. Kit pages (auth, settings) keep Instrument Sans.

Sizes are given at the design size of each layout; see §3 for scaling.

| Token | TV 1920 | Laptop 1440 | Phone 390 | Weight | Notes |
|---|---|---|---|---|---|
| `title-now` | 120 / 0.9 | n/a | 52 / 0.95 | 700, `wdth` 92, `opsz` 96, tracking −0.03em | Now-playing title |
| `display-chat` | n/a | 64 / 1.1 | 38 / 1.08 | 300, tracking −0.02em | First DJ question |
| `chat` | n/a | 26 / 1.4 | 18 / 1.38 | 400 | Conversation lines |
| `reason` | 28 | n/a | 16 | 300 (guest name 600 white) | "Burna Boy, because Maria loves Ozark" |
| `event-name` | 26 | n/a | 17 | 400 | "Lisbon Rooftop" |
| `code` | 30, tracking 0.12em | n/a | 15, tracking 0.1em | 600 | "DEMO" |
| `list` | 22 | 24 | 16 | 500 | Up next titles, quick starts |
| `meta` | 16 | 18 | 13 | 400 | Guest names in lists, captions |
| `micro` | 15 | 16 | 11–12 | 400 | Labels ("Up next", mix labels, axis labels) |
| `wordmark` | n/a | 22 | 16 | 400, `--rt-text` @ 0.7 | "roomtone" top-left |

### Spacing and shape

| Token | Value | Use |
|---|---|---|
| `--rt-gutter-tv` | 80px | TV outer margin |
| `--rt-gutter-laptop` | 96px | Setup outer margin |
| `--rt-gutter-phone` | 20px | Phone outer margin |
| `--rt-chat-col` | 900px, starting at x 270 | Laptop conversation column |
| `--rt-radius-pill` | 999px | Buttons |
| `--rt-tap` | 44px | Minimum tap target, everywhere |

No cards, no panels, no glass. Text sits directly on space. The only filled shapes are the lava, the CTA, the QR and the round send/pause buttons (`rgba(237,237,242,0.10–0.12)`).

## 3. Host screen

### 3.1 Layout and scaling

- Design canvas: 1920×1080 (TV) and 390×844 (phone). Use the **TV layout when the viewport is landscape and ≥ 1024px wide**, otherwise the phone layout.
- TV layout scales as one 16:9 stage: compute `scale = min(innerWidth/1920, innerHeight/1080)` and lay out HUD in design pixels inside a scaled container, centred, with `--rt-bg` filling the letterbox. This keeps a laptop, a TV and a projector identical.
- The 3D canvas is full-bleed and transparent; the star field and background sit behind it in CSS/SVG (cheaper than in-scene stars).

### 3.2 Regions (TV, design px)

| Region | Position | Content |
|---|---|---|
| Header | left 80, top 64 | Event name, then genre dots (gap 18) |
| Join | right 80, top 64 | "roomtone.app" (`micro`, `--rt-text-4`) over code (`code`), QR 96×96 radius 10 on `--rt-text` |
| Pool | full canvas; islands roughly in the band y 100–700 | Islands, guest points, ripples |
| Guest stream | right 80, top 300, 280×420, bottom-aligned | Newest at the bottom; top 40% fades out (mask) |
| Now playing | left 80, bottom 64, width 860 | Title, reason line, mix bar (width 620) |
| Up next | right 80, bottom 64, width 460, right-aligned | "Up next" label + 3 lines |

Phone (390×844): header row top 54 (event + dots left, "DEMO · Invite" right); pool in y 110–430; "Just in" line at 440; now playing at 488; up next at 646 (left-aligned, dot first, guest right); playback bar bottom 30 with a hairline top border: "Room" · round Pause (52) · "Skip".

### 3.3 Components

| Component | Data | Rules |
|---|---|---|
| Genre dots | clusters in the set | Playing cluster first: 14px (phone 9) with `0 0 0 3px` 25% ring and glow; others 9px (phone 6) in mix order. Max 5. `role="img"`, label "Genres in the set: Afrobeats playing now, then 80s Pop, Fado and UK Garage" |
| Island | cluster | Metaball body in the cluster's lava gradient + soft haze of the base colour behind it (blur ≈ 50px TV). Radius = `r0 · √members` (TV `r0` ≈ 100px for one guest; Afrobeats with 3 = 170–210). 2–3 satellite blobs merge into the body. Label centred near the top, `label ink`, 26–36px TV, scales with island |
| Guest point | guest | White dot 5px + 14px white halo @ 0.45 (TV). Placed inside their dominant cluster's island. Name below in `meta` with a 45% dark stroke for legibility on lava |
| Focus guest | `nowPlaying.reasonGuestId` | Dot 9px, halo 28px @ 0.75, name 26/700. Five ripple rings centred on them (see motion) |
| Guest stream row | guest | Name (20/500; focus guest 24/700) over "loves {first love}" (`micro`, `--rt-text-4`), cluster dot 8px with glow on the right |
| Now playing | `nowPlaying` | Title `title-now`; reason line = `{artist}, ` + `reasonText` with the reason guest's name in 600 white |
| Mix bar | set_items by cluster | 2px segments, gap 4, flex = track count per cluster (played + playing + queued). Playing cluster full opacity, others 0.5. Labels under each segment; playing one reads "{Genre}, now" in `--rt-text`. Max 4 segments; merge the rest into "Other" |
| Up next | first 3 queued | Title (`list`, ellipsis), guest first name (`meta`, `--rt-text-4`), cluster dot 8px |
| Join block | event code | Code + QR. Phone: "DEMO · Invite" button opens `navigator.share({ url })`, falls back to copying the link and showing "Link copied" for 2s |

### 3.4 States

| State | What shows |
|---|---|
| Empty room (0 guests) | No islands. One small ember (slot 1 lava, r ≈ 34 TV) with two faint rings, left of centre. QR grows to 168px and moves to centre-right with "roomtone.app" + code under it. Bottom-left: "The room warms up as people join" (`meta`, `--rt-text-3`). No now-playing, no up next |
| 1–3 guests | Islands appear as they're assigned; until the agent clusters someone, their point floats near the ember |
| Nothing playing yet | Now-playing block hidden; up next shows if queued |
| Queue < 3 | Show what exists; hide "Up next" when empty |
| Many guests (> 12) | Names in the pool only for the focus guest and the last 3 who joined; the stream still lists everyone (last 8 visible) |
| Long text | Title: shrink to fit 860px down to 72px, then 2 lines max, then ellipsis. Up next titles: one line, ellipsis. Guest names in the pool: 14 chars + "…" |
| Reconnecting (Phase 2+) | Keep the last state on screen; small "Reconnecting…" in `micro` under the event name |

### 3.5 Motion

| Element | Trigger | Animation | Duration | Easing |
|---|---|---|---|---|
| Ripple rings | always, around the focus guest | Ring spawns at r 24, grows to r 410 (TV), opacity 0.85 → 0, stroke 3 → 1; a new ring every 0.8s, 5 alive | 4s each | ease-out (cubic) |
| Island wobble | always | Satellites orbit their body slowly; body radius breathes ±3% with `audioLevel` | 20–40s orbits | sine |
| Island grow | member count changes | Radius springs to the new size | ~1.2s | spring (tension 120, friction 18) |
| Guest arrives | guest joins | Point drops in from above the island, lands, one landing ripple; their row slides into the stream from below and older rows move up | 900ms + 600ms | ease-out |
| Focus change | new track | Rings fade out on the old guest (400ms), start on the new one; title cross-fades (300ms) | — | ease-in-out |
| Camera | always | Existing slow drift from `CameraRig`, smaller amplitude: the pool must never leave its band | — | — |

`prefers-reduced-motion`: no wobble, no drift, one static ring at r 70 instead of the ripple loop, instant updates.

### 3.6 Building it (keep vs replace)

Keep: `store.ts`, `live.ts`, `Conductor.tsx`, `CameraRig.tsx` (tone down), `Effects.tsx` (bloom much lower: the lava glows, not the whole scene), `labels.ts`, `useDevKeys.ts` (G, N, E, H keep working), `fakeGuests.ts`, `HostRoomProps`.

Replace: `Star.tsx`, `Clusters.tsx` (nebulas), `Grooves.tsx` (floor), `ReasonBeam.tsx` (beam → ripples), `Atmosphere.tsx` (stars move to CSS/SVG), `Hud.tsx` (new layout).

Islands: use drei's `MarchingCubes` (already a dependency through drei), one instance per cluster with its own material, viewed from a slightly tilted camera so the lava reads as a glossy liquid with depth; this keeps the host screen genuinely 3D. Guest points and ripples are flat meshes on the island surface plane. No new dependencies.

## 4. Setup: talk to the DJ

### 4.1 Layout

- Laptop 1440×900: wordmark at 96/64; conversation column 900px from x 270, top 110; composer pinned 64 from the bottom, same column, underline `--rt-line` 2px.
- Phone 390×844: gutter 20, wordmark top 58, conversation from top 100, CTA block above the composer, composer 40 from the bottom.
- Background: `--rt-bg` + star field. First open adds a soft ember haze behind the DJ dot.

### 4.2 Pieces

| Piece | Spec |
|---|---|
| DJ line | 16px dot (phone 11) with radial lava gradient + glow, then text in `--rt-ink`. **Dot colour = the occasion**: party/dinner/default slot 1 orange; drive/road trip slot 3 blue (`#4C7DFF`, dark stop `#2440B8`); workout slot 6 |
| Your line | Right-aligned, max 620px (phone 280), `--rt-text-3`. No bubbles |
| First open | `display-chat` "Hey. What are we playing for?" + "Tell me in your own words. I'll shape the music around it, and everyone with you can add to it." + quick starts: A party, A long drive, A family road trip, Dinner with friends, Cooking at home, A workout. Each is a text button with a 10px dot in its colour; tapping sends it as your message |
| Plan curve | SVG, width 680 (phone 327), height 170 (phone 124). Stroke: gradient through the plan's colours, 3px core + 10px blurred glow at 0.5. Baseline hairline. Handles: white 6px dot in a 15px 12% halo; the peak handle is 8px and its label is `--rt-ink`. X axis is **clock time** for events ("20:00 · 21:30 dinner ends · 23:00 peak · 00:30") or **trip time** for drives ("Leave · 1 h · 2 h · Arrive", with phase labels above the curve: "Sing-alongs", "Nap time", "Wake-up") |
| I heard | "I heard" (`--rt-text-4`, laptop only) then fact buttons with a dashed underline: occasion, name, time range or length, output ("Speaker + TV") or rule ("Clean versions only") |
| CTA | Pill, `--rt-cta`, 56px (phone 52, full width). Label per occasion: "Open the room" (default), "Start the drive" (drive/road trip). Caption beside or below: "Guests join by code or a WhatsApp link." / "Everyone in the car can add songs from their phone." |
| Composer | Underline input + 44px round send (↑). Placeholder per stage: first open "Maria's 30th on the rooftop, or four hours to the coast with the kids" (phone "4 hours to the coast with the kids"); after a plan "Anything to avoid?" |

### 4.3 Interactions and states

| Element | State | Behaviour |
|---|---|---|
| Curve handle | drag | Pointer drag moves energy (vertical, snaps to 1–5) and time (horizontal, snaps to 15 min); the curve redraws live |
| Curve handle | keyboard | Focusable (`role="slider"`, `aria-valuetext="23:00, energy 5 of 5, peak"`); Up/Down energy, Left/Right ±15 min |
| Fact button | tap | Turns into an inline input with the same text; Enter saves, Esc cancels; the DJ acknowledges in one short line |
| DJ | thinking | Three 6px dots in the DJ colour pulsing in sequence where the next DJ line will appear |
| DJ | error | DJ line: "I lost the thread. Say that again?" Your message stays; nothing is lost |
| Composer | empty | Send disabled (0.4 opacity), still focusable |
| Composer | long input | Wraps to 3 lines, then scrolls; max 500 characters |
| CTA | loading | Label → "Opening…", disabled; on success go to the host screen |

### 4.4 Data

Setup writes the event: `name`, `occasion` (free text the agent normalises: party, birthday, drive, road trip, dinner, cooking, workout, other) and `energy_plan` json. Extend the existing `[{at, energy, label}]` shape with a top-level `timeline` instead of a migration:

```json
{ "timeline": "clock", "points": [{ "at": "20:00", "energy": 3, "label": "Arrivals" }], "rules": { "clean": false } }
{ "timeline": "trip", "minutes": 180, "points": [{ "at": 0, "energy": 4, "label": "Sing-alongs" }], "rules": { "clean": true } }
```

Read the old array shape as `timeline: "clock"` so the DEMO seed keeps working.

## 5. Accessibility

- Text contrast ≥ 4.5:1: nothing below `--rt-text-4` for text. Lava labels use the dark label inks above (≥ 4.5:1 on their lava).
- Host screen: now-playing region is `aria-live="polite"` ("Now playing Last Last by Burna Boy, because Maria loves Ozark"); guest arrivals announce politely ("Maria joined"). The 3D canvas is `aria-hidden`; everything meaningful is in the HTML HUD.
- Setup: conversation is an ordered list with "DJ:" / "You:" visually hidden prefixes; new DJ lines announce politely. Focus order: messages → curve handles → fact buttons → CTA → composer. After each DJ reply, focus stays in the composer.
- Every tap target ≥ 44px. Visible focus: 2px white outline, 4px offset (phone 3px).
- Respect `prefers-reduced-motion` (see 3.5).

## 6. Decisions still open (ask Allan before doing)

- **QR code**: a real QR needs a dependency (for example `qrcode` on npm). Keep the placeholder until Phase 2 and ask then.
- **Guest join screen**: build simple (see top), and tell Allan when it's ready so he can review it.
