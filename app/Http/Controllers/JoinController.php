<?php

namespace App\Http\Controllers;

use App\Enums\LoveStatus;
use App\Enums\SetItemStatus;
use App\Events\GuestJoined;
use App\Events\RoomUpdated;
use App\Http\Requests\JoinRoomRequest;
use App\Jobs\ResolveGuest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Services\Dj\GuestResolver;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guests join from their phone: no app, no login. A cookie remembers who they are in this room.
 */
class JoinController extends Controller
{
    /** How long a phone stays "in" a room. */
    private const COOKIE_MINUTES = 60 * 48;

    public function show(Request $request, string $code): Response|RedirectResponse
    {
        $event = $this->event($code);

        if (! $event->hasEnded() && $this->guest($request, $event) !== null) {
            return redirect()->route('events.me', $event->code);
        }

        return Inertia::render('Join', ['event' => $this->summary($event)]);
    }

    public function store(JoinRoomRequest $request, string $code): RedirectResponse
    {
        $event = $this->event($code);

        // An ended party takes no one new (the join page says so).
        if ($event->hasEnded() || $this->guest($request, $event) !== null) {
            return redirect()->route($event->hasEnded() ? 'events.join' : 'events.me', $event->code);
        }

        $guest = $event->guests()->create(['name' => (string) $request->validated('name'), 'color' => '#ffffff']);

        foreach ((array) $request->validated('loves') as $love) {
            $guest->loves()->create(['raw_text' => GuestResolver::clean((string) $love)]);
        }

        GuestJoined::dispatch($guest);
        ResolveGuest::dispatch($guest);

        return redirect()->route('events.me', $event->code)
            ->withCookie(Cookie::make($this->cookieName($event), (string) $guest->id, self::COOKIE_MINUTES));
    }

    /**
     * The guest's own page: how the DJ read their loves, and their song once it is in the set.
     */
    public function me(Request $request, string $code): Response|RedirectResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);

        if ($guest === null) {
            return redirect()->route('events.join', $event->code);
        }

        if ($event->hasEnded()) {
            return Inertia::render('Guest', ['event' => $this->summary($event), 'guest' => ['name' => $guest->name, 'loves' => []], 'song' => null, 'reading' => false, 'youtubePaused' => false]);
        }

        $items = $event->setItems()->with('track')
            ->where('reason_guest_id', $guest->id)
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])
            // The track actually playing wins. For upcoming tracks, show their blend first, then the next one.
            ->orderByRaw('case when status = ? then 0 else 1 end', [SetItemStatus::Playing->value])
            ->orderByRaw('(reason_love_id is null and host_request_id is null) desc')
            ->orderBy('position')->get();
        // The host hides songs without a video whenever a playable one exists. Show that same playable choice to
        // the guest instead of a provisional song that cannot reach the player.
        $item = $items->first(fn ($candidate) => $candidate->track->youtube_video_id !== null) ?? $items->first();
        $playing = $event->setItems()->where('status', SetItemStatus::Playing)->value('position');

        return Inertia::render('Guest', [
            'event' => $this->summary($event),
            'guest' => [
                'name' => $guest->name,
                'loves' => $guest->loves()->orderBy('id')->get()->map(fn (GuestLove $love) => [
                    'text' => $love->raw_text,
                    'status' => $love->status->value,
                    // What the DJ read it as, when that's not just the text again ("Rice and beans" → Rio de Janeiro).
                    'as' => $love->resolved_name !== null && Str::lower($love->resolved_name) !== Str::lower($love->raw_text)
                        ? $love->resolved_name : null,
                    // Read as a place: its music stands in for the love ("music from Rio de Janeiro").
                    'place' => $love->qloo_type === 'location',
                    // Read through Qloo's taste for a dish or cuisine.
                    'dish' => $love->qloo_type === 'tag',
                ])->all(),
            ],
            'song' => $item === null ? null : [
                'title' => $item->track->title,
                'artist' => $item->track->artist,
                'reason' => $item->reason_text,
                'playing' => $item->status === SetItemStatus::Playing,
                'playable' => $item->track->youtube_video_id !== null,
                // Tracks before theirs, counting from the one playing now.
                'ahead' => $item->track->youtube_video_id === null || $item->status === SetItemStatus::Playing || $playing === null ? 0
                    : $event->setItems()->where('status', SetItemStatus::Queued)->where('position', '<', $item->position)
                        ->whereHas('track', fn ($tracks) => $tracks->whereNotNull('youtube_video_id'))->count() + 1,
            ],
            'reading' => $guest->loves()->where('status', LoveStatus::Pending)->exists(),
            'youtubePaused' => YouTubeClient::paused(),
        ]);
    }

    /**
     * The guest changes their name or loves. Changed loves are read again (stage 1), and the set rebuilds after,
     * as for a new guest. Love ids stay, so tracks already played keep their reasons.
     */
    public function update(JoinRoomRequest $request, string $code): RedirectResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);

        // After the end nothing is read again: changing loves would cost Qloo calls for nobody.
        if ($guest === null || $event->hasEnded()) {
            return redirect()->route($guest === null ? 'events.join' : 'events.me', $event->code);
        }

        $guest->update(['name' => (string) $request->validated('name')]);
        $texts = array_values((array) $request->validated('loves'));
        $changed = false;

        foreach ($guest->loves()->orderBy('id')->get()->values() as $i => $love) {
            $text = GuestResolver::clean((string) ($texts[$i] ?? ''));

            if ($text !== $love->raw_text) {
                $love->update(['raw_text' => $text, 'status' => LoveStatus::Pending, 'pool' => null,
                    'qloo_entity_id' => null, 'qloo_type' => null, 'resolved_name' => null]);
                $changed = true;
            }
        }

        if ($changed) {
            ResolveGuest::dispatch($guest);
        }

        RoomUpdated::for($event);

        return redirect()->route('events.me', $event->code);
    }

    /**
     * @return array{code: string, name: string, ended: bool}
     */
    private function summary(Event $event): array
    {
        return ['code' => $event->code, 'name' => $event->name, 'ended' => $event->hasEnded()];
    }

    private function event(string $code): Event
    {
        return Event::query()->where('code', strtoupper($code))->firstOrFail();
    }

    private function guest(Request $request, Event $event): ?Guest
    {
        $id = $request->cookie($this->cookieName($event));

        return is_string($id) && ctype_digit($id) ? $event->guests()->whereKey((int) $id)->first() : null;
    }

    private function cookieName(Event $event): string
    {
        return 'roomtone_guest_'.$event->code;
    }
}
