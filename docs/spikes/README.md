# Spikes

Python (standard library only) scripts used to test the Roomtone claim against the live Qloo, Anthropic and YouTube services. They are reference material for the development session, not part of the app.

Needs `.env` at the repo root with `QLOO_API_KEY`, `AI_PROVIDER_KEY` (an Anthropic API key) and `AI_MODEL` (the spikes used `claude-haiku-5-5`).

| File | What it is |
|---|---|
| `q.py` | Qloo HTTP helper with 429 backoff |
| `agent.py` | Claude Messages API helper with a tool loop, prompt caching (`CACHE=1`), Qloo search/insights tools |
| `parties.py` | The fictional 20-guest party (60 loves) |
| `pipeline.py` | The full reference design: stage 1 with retry, stage 2 with the validating `add_track`, host steering, two edge-case guests |
| `yt.py` | YouTube search, embeddability and oEmbed checks by scraping (spike only; production uses the Data API) |

```bash
CACHE=1 python3 docs/spikes/pipeline.py        # about 3 minutes, a few US cents
```

Outputs go to `/tmp/roomtone-spikes/` (override with `SPIKE_OUT`). **They contain Qloo response data: never move them into the repo** (Qloo terms).
