<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\HostRequestController;
use App\Http\Controllers\HostRoomController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\NowPlayingController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/new', [SetupController::class, 'show'])->name('setup');
Route::post('/new/turn', [SetupController::class, 'turn'])->middleware('throttle:30,60')->name('setup.turn');
Route::post('/new', [SetupController::class, 'store'])->middleware('throttle:10,60')->name('setup.store');
Route::post('/demo', [DemoController::class, 'store'])->middleware('throttle:6,1')->name('demo.store');
Route::get('/e/{code}', [JoinController::class, 'show'])->name('events.join');
Route::post('/e/{code}/guests', [JoinController::class, 'store'])->middleware('throttle:10,1')->name('events.guests.store');
Route::get('/e/{code}/me', [JoinController::class, 'me'])->name('events.me');
Route::get('/e/{code}/host', HostRoomController::class)->name('events.host');
Route::post('/e/{code}/requests', [HostRequestController::class, 'store'])->middleware('throttle:10,1')->name('events.requests.store');
Route::post('/e/{code}/now-playing', NowPlayingController::class)->middleware('throttle:60,1')->name('events.now-playing');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
