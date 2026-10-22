<?php

namespace App\Http\Controllers;

use App\Ai\Agents\SetupDj;
use App\Models\Event;
use App\Services\Dj\SetupDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Setup: the host talks to the DJ, who turns it into a room with an energy plan.
 */
class SetupController extends Controller
{
    public const LOST = 'I lost the thread. Say that again?';

    public function show(): Response
    {
        return Inertia::render('Setup');
    }

    /**
     * One turn of the chat. Stateless: the page sends the conversation and the draft; the DJ answers with a reply
     * and an updated draft, which SetupDraft checks.
     */
    public function turn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:24'],
            'messages.*.from' => ['required', Rule::in(['host', 'dj'])],
            'messages.*.text' => ['required', 'string', 'max:500'],
            'draft' => ['nullable', 'array'],
            'now' => ['nullable', 'string', 'max:40'],
        ]);

        $draft = SetupDraft::from((array) ($data['draft'] ?? []));
        $lines = array_map(
            fn (array $m) => ($m['from'] === 'host' ? 'Host (untrusted text): ' : 'DJ: ').json_encode($m['text'], JSON_UNESCAPED_UNICODE),
            $data['messages'],
        );

        $prompt = 'Host\'s local time now: '.($data['now'] ?? 'unknown')."\n"
            .'Current draft: '.json_encode($draft, JSON_UNESCAPED_UNICODE)."\n\nConversation:\n".implode("\n", $lines);

        try {
            $answer = SetupDj::make()->prompt($prompt);
        } catch (Throwable) {
            return response()->json(['reply' => self::LOST, 'draft' => $draft, 'ready' => SetupDraft::ready($draft)], 502);
        }

        $next = SetupDraft::from($answer->toArray());
        $reply = Str::limit(trim(is_string($answer['reply'] ?? null) ? $answer['reply'] : ''), 400, '…');

        return response()->json([
            'reply' => $reply === '' ? self::LOST : $reply,
            'draft' => $next,
            'ready' => SetupDraft::ready($next),
        ]);
    }

    /**
     * "Open the room": create the event from the agreed draft and go to its host link.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'draft' => ['required', 'array'],
            'tz' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $draft = SetupDraft::from($data['draft']);

        if (! SetupDraft::ready($draft)) {
            return back()->withErrors(['draft' => 'Tell the DJ a little more first.']);
        }

        do {
            $code = Str::upper(Str::random(6));
        } while (Event::where('code', $code)->exists());

        $plan = ['timeline' => $draft['timeline'], 'points' => $draft['points'], 'tz' => $data['tz'] ?? config('app.timezone')];
        if ($draft['timeline'] === 'trip') {
            $plan['minutes'] = $draft['minutes'];
        }

        $event = Event::create([
            'code' => $code,
            'name' => $draft['name'] ?? 'Our room',
            'occasion' => $draft['occasion'],
            'energy_plan' => $plan,
            'host_token' => Str::random(40),
        ]);

        return redirect()->route('events.host', ['code' => $event->code, 'key' => $event->host_token]);
    }
}
