<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    TrustProxies::flushState();
});

beforeEach(function () {
    Route::get('/_test/ip', fn () => request()->ip().' '.(request()->isSecure() ? 'https' : 'http'));
});

test('without trusted proxies the forwarded headers are ignored', function () {
    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->assertSeeText('127.0.0.1 http');
});

test('behind Cloudflare the real visitor IP and scheme come from the forwarded headers', function () {
    config(['app.trusted_proxies' => '*']);
    (new AppServiceProvider(app()))->boot();

    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->assertSeeText('203.0.113.7 https');
});
