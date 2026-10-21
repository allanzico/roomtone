<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Dj\DemoParty;
use Illuminate\Http\RedirectResponse;

class DemoController extends Controller
{
    /**
     * "Try a demo party": the visitor gets their own copy of the demo and lands on its host link, with requests.
     */
    public function store(DemoParty $demo): RedirectResponse
    {
        if (! Event::query()->where('code', DemoParty::CODE)->exists()) {
            return back()->withErrors(['demo' => 'The demo party is still being set up. Try again in a few minutes.']);
        }

        $event = $demo->copy();

        return redirect()->route('events.host', ['code' => $event->code, 'key' => $event->host_token]);
    }
}
