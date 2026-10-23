<?php

namespace App\Http\Controllers;

use App\Events\RoomUpdated;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EndPartyController extends Controller
{
    /**
     * The host ends the party. Guests' pages say so, and nothing more is read or built for the room: every love
     * change would cost Qloo calls for a party nobody is listening to.
     */
    public function __invoke(Request $request, string $code): Response
    {
        $event = Event::query()->where('code', strtoupper($code))->firstOrFail();

        abort_unless($event->isHostKey($request->input('key')), 403);

        if (! $event->hasEnded()) {
            $event->update(['ended_at' => now()]);
            RoomUpdated::for($event);
        }

        return response()->noContent();
    }
}
