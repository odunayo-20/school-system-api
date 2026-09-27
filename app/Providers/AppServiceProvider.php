<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters for the API. Limits are configurable so a deployment can
     * tighten or relax them without a code change.
     */
    protected function configureRateLimiting(): void
    {
        // Default ceiling applied to every /api/v1 request.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            (int) env('API_RATE_LIMIT_PER_MINUTE', 60)
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Credential stuffing protection: few attempts per email + IP pair.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(
            (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5)
        )->by($this->throttleKey($request)));

        // Password reset link generation and consumption.
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(
            (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 5)
        )->by($this->throttleKey($request)));
    }

    /**
     * Key on the submitted email address together with the client IP, so one attacker
     * cannot lock out an entire school by hammering a shared address, and cannot
     * spread attempts across many addresses from a single host unnoticed.
     */
    protected function throttleKey(Request $request): string
    {
        $email = $request->input('email');

        return mb_strtolower(is_string($email) ? trim($email) : '').'|'.$request->ip();
    }
}
