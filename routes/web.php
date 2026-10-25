<?php

use App\Http\Controllers\EndPartyController;
use App\Http\Controllers\HostRequestController;
use App\Http\Controllers\HostRoomController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\NowPlayingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UnavailableVideoController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/new', [SetupController::class, 'show'])->name('setup');
Route::post('/new/turn', [SetupController::class, 'turn'])->middleware('throttle:setup-turn')->name('setup.turn');
Route::post('/new', [SetupController::class, 'store'])->middleware('throttle:setup-room')->name('setup.store');
Route::get('/e/{code}', [JoinController::class, 'show'])->name('events.join');
Route::post('/e/{code}/guests', [JoinController::class, 'store'])->middleware('throttle:join')->name('events.guests.store');
Route::get('/e/{code}/me', [JoinController::class, 'me'])->name('events.me');
Route::put('/e/{code}/me', [JoinController::class, 'update'])->middleware('throttle:guest-update')->name('events.me.update');
Route::get('/e/{code}/host', HostRoomController::class)->name('events.host');
Route::post('/e/{code}/requests', [HostRequestController::class, 'store'])->middleware('throttle:host-request')->name('events.requests.store');
Route::post('/e/{code}/now-playing', NowPlayingController::class)->middleware('throttle:host-screen')->name('events.now-playing');
Route::post('/e/{code}/end', EndPartyController::class)->middleware('throttle:host-screen')->name('events.end');
Route::post('/e/{code}/unavailable', UnavailableVideoController::class)->middleware('throttle:host-screen')->name('events.unavailable');
