# How Roomtone uses Qloo

Roomtone calls the Qloo HTTP API from its Laravel backend. The [Qloo hackathon kit](https://github.com/qloo/qloo-hackathon-kit) documents CLI and MCP starter routes; this PHP application keeps its own client in [`app/Services/Qloo/QlooClient.php`](../app/Services/Qloo/QlooClient.php). The key stays in server environment configuration, never in the browser. The Laravel AI SDK supplies the agent; Qloo supplies the ranked taste results the agent is allowed to choose from.

## Request to result

1. A guest enters three cultural interests. For each one, `GuestResolver` calls Qloo `/search`; the model selects a candidate from the returned list. A search hit is only a candidate, and an interest stays unresolved when no supported match is selected.
2. For a selected show, artist, book, or other supported entity, Roomtone calls `/v2/insights` with `filter.type=urn:entity:artist` and `signal.interests.entities=<selected ID>`. A city uses `signal.location.query=<place name>`. A dish is used only if Qloo's `/v2/tags` returns a suitable taste tag **and** its artist results differ from an ignored-tag baseline; it then uses `signal.interests.tags=<tag ID>`.
3. Roomtone asks for a guest's combined signals to find a middle ground. If the host's plan calls for low or high energy, it also asks Qloo for artists with `filter.tags=urn:tag:style:qloo:calm` or `...:energetic`. A mid-party genre request first resolves a music genre tag with `/v2/tags`, then filters new artist results for each guest. These are separate Qloo calls, so changing the plan can change the candidate artists.
4. The Qloo artist data passed to the DJ agent is limited to ranked IDs, names, notable song titles, genres and a few display tags. Its `add_track` tool checks every choice against the stored pool. Roomtone writes the reason from the matched guest interest and Qloo pool; YouTube supplies an embeddable video for playback. Qloo does not supply BPM, track energy, or the final song choice.

For example, a fictional guest could enter `Person of Interest`, `beef steak`, and `Amsterdam`. The first input must resolve to the intended show before its Qloo entity ID is used. The dish can become a Qloo tag only after the baseline check. Amsterdam is sent as a location query, not as an entity signal. The resulting artist lists are then filtered by the host's energy plan. This example describes the request shape; it does not claim a particular song will be returned.

## Where to inspect the flow

| Part | Code |
| --- | --- |
| HTTP requests, credentials, limits, and caching | [`QlooClient.php`](../app/Services/Qloo/QlooClient.php) |
| Search choice, per-interest pools, and combined signals | [`GuestResolver.php`](../app/Services/Dj/GuestResolver.php) |
| Energy-specific Qloo pools | [`SetBuilder.php`](../app/Services/Dj/SetBuilder.php) |
| Mid-party genre and mood requests | [`RequestSteerer.php`](../app/Services/Dj/RequestSteerer.php) |
| Allowed tracks and generated reasons | [`SetValidator.php`](../app/Services/Dj/SetValidator.php) |

`QLOO_API_KEY` and `QLOO_API_URL` are set in `.env` locally or as server secrets in deployment. `.env.example` contains placeholders. Tests fake HTTP responses; no live Qloo response or key is committed. See the [run instructions](../README.md#run-it-locally).

## Limits and data handling

Qloo results are aggregate taste affinities, not proof of any guest's personal preferences. The app interprets them as music candidates for a room. Ambiguous inputs may stay unresolved; the guest can edit them with more detail, but the app does not yet ask a follow-up question. Genre, mood, and dish filters may produce few or no usable songs; an unresolved input is shown as such. The agent estimates BPM and energy, so those values are not measured audio features. YouTube availability can remove an otherwise valid Qloo-backed choice.

Guest names and local guest IDs are not sent in Qloo requests. The interest text itself **is** sent to Qloo search, so demos should use fictional guests and public cultural interests, not private or sensitive information. Credentials are kept out of source control, prompts, and client bundles. Qloo calls use a three-slot shared lock and bounded retry for rate limits.
