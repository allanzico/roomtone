<?php

namespace App\Http\Controllers;

use App\Enums\LoveStatus;
use App\Enums\SetItemStatus;
use App\Events\GuestJoined;
use App\Http\Requests\JoinRoomRequest;
use App\Jobs\ResolveGuest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Services\Dj\GuestResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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

        if ($this->guest($request, $event) !== null) {
            return redirect()->route('events.me', $event->code);
        }

        return Inertia::render('Join', ['event' => ['code' => $event->code, 'name' => $event->name]]);
    }

    public function store(JoinRoomRequest $request, string $code): RedirectResponse
    {
        $event = $this->event($code);

        if ($this->guest($request, $event) !== null) {
            return redirect()->route('events.me', $event->code);
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

        $item = $event->setItems()->with('track')
            ->where('reason_guest_id', $guest->id)
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])
            ->orderBy('position')->first();
        $playing = $event->setItems()->where('status', SetItemStatus::Playing)->value('position');

        return Inertia::render('Guest', [
            'event' => ['code' => $event->code, 'name' => $event->name],
            'guest' => [
                'name' => $guest->name,
                'loves' => $guest->loves()->orderBy('id')->get()->map(fn (GuestLove $love) => [
                    'text' => $love->raw_text,
                    'status' => $love->status->value,
                ])->all(),
            ],
            'song' => $item === null ? null : [
                'title' => $item->track->title,
                'artist' => $item->track->artist,
                'reason' => $item->reason_text,
                'playing' => $item->status === SetItemStatus::Playing,
                // Tracks before theirs, counting from the one playing now.
                'ahead' => $item->status === SetItemStatus::Playing || $playing === null ? 0
                    : $event->setItems()->where('status', SetItemStatus::Queued)->where('position', '<', $item->position)->count() + 1,
            ],
            'reading' => $guest->loves()->where('status', LoveStatus::Pending)->exists(),
        ]);
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
