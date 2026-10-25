<?php

use App\Ai\Agents\SetupDj;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::create(['code' => 'ROOMA1', 'name' => 'A', 'host_token' => 'key-a']);
    Event::create(['code' => 'ROOMB1', 'name' => 'B', 'host_token' => 'key-b']);
});

test('each action has its own limit: a playing host screen does not use up the setup chat', function () {
    // The host screen reports track changes from the same IP (a party's one Wi-Fi).
    foreach (range(1, 40) as $i) {
        $this->postJson('/e/ROOMA1/now-playing', ['key' => 'wrong', 'item' => 1])->assertForbidden();
    }

    SetupDj::fake([['reply' => 'A party, nice.', 'choices' => [], 'occasion' => 'party', 'name' => null, 'timeline' => 'clock', 'minutes' => null, 'points' => []]]);

    $this->postJson('/new/turn', ['messages' => [['from' => 'host', 'text' => 'A party']]])->assertOk();
});

test('joining is limited per room and IP, so a full party on one Wi-Fi fits and another room is not affected', function () {
    // Invalid forms still count as attempts.
    foreach (range(1, 60) as $i) {
        $this->post('/e/ROOMA1/guests', [])->assertSessionHasErrors();
    }

    $this->post('/e/ROOMA1/guests', [])->assertTooManyRequests();
    $this->post('/e/ROOMB1/guests', [])->assertSessionHasErrors();
});
