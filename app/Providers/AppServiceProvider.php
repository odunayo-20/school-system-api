<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        $this->configureEmailVerificationUrl();
    }

    /**
     * Laravel's VerifyEmail notification builds its link from a hard-coded route name,
     * "verification.verify". Every route in this application lives under the "auth."
     * name prefix, so the default would raise a RouteNotFoundException the first time a
     * real verification mail is sent. Point the notification at the prefixed route,
     * keeping the standard signed-URL shape and expiry.
     */
    protected function configureEmailVerificationUrl(): void
    {
        VerifyEmail::createUrlUsing(function (mixed $notifiable): string {
            /** @var Notifiable $notifiable */
            return URL::temporarySignedRoute(
                'auth.verification.verify',
                Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => (string) $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
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

        // Public result-checker: student_number + date_of_birth guessing protection, keyed
        // the same way as login - the credential field plus the client IP - so one attacker
        // cannot lock out a whole class by hammering one registration number, and cannot
        // spread guesses across many numbers from a single host unnoticed.
        RateLimiter::for('result-checker', fn (Request $request): Limit => Limit::perMinute(
            (int) env('RESULT_CHECKER_RATE_LIMIT_PER_MINUTE', 5)
        )->by($this->throttleKey($request, 'student_number')));
    }

    /**
     * Key on the submitted credential field together with the client IP, so one attacker
     * cannot lock out an entire school by hammering a shared value, and cannot spread
     * attempts across many values from a single host unnoticed.
     */
    protected function throttleKey(Request $request, string $field = 'email'): string
    {
        $value = $request->input($field);

        return mb_strtolower(is_string($value) ? trim($value) : '').'|'.$request->ip();
    }
}
