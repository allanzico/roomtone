<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->trustProxies();
        $this->rateLimits();
    }

    /**
     * One limit per action, each with its own counter (a plain throttle:N,M shares one counter per IP across every
     * route, so a playing host screen used up the setup chat's allowance). A party is often one Wi-Fi, so one IP:
     * guest limits are per room and IP, and the host screen's limits per room.
     */
    protected function rateLimits(): void
    {
        $room = fn (Request $request) => strtoupper((string) $request->route('code'));

        RateLimiter::for('setup-turn', fn (Request $request) => Limit::perHour(60)->by($request->ip()));
        RateLimiter::for('setup-room', fn (Request $request) => Limit::perHour(20)->by($request->ip()));
        RateLimiter::for('join', fn (Request $request) => Limit::perMinute(60)->by($room($request).'|'.$request->ip()));
        RateLimiter::for('guest-update', fn (Request $request) => Limit::perMinute(20)->by($room($request).'|'.$request->ip()));
        RateLimiter::for('host-request', fn (Request $request) => Limit::perMinute(10)->by($room($request)));
        RateLimiter::for('host-screen', fn (Request $request) => Limit::perMinute(120)->by($room($request)));
    }

    /**
     * Behind Cloudflare every request arrives from a Cloudflare address: trust the forwarded headers so rate limits
     * see the real visitor IP (config app.trusted_proxies: "*", or a comma-separated list).
     */
    protected function trustProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
