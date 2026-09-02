<?php

namespace App\Providers;

use App\Models\User;
use App\Support\EmailVerificationLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request / per job. Everything tenant-scoped reads it.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->configurePasswordRules();
        $this->configureRateLimiting();
        $this->configureNotificationUrls();
    }

    /**
     * Spec 7.1 / 8: minimum 12 characters, and rejected outright if it appears
     * in a known breach corpus. The breach check needs network access, so it is
     * production only.
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12);

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * The four limiters of the HTTP contract, section 3.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }

    /**
     * Both mails link to the SPA, never to a Blade page: this backend is
     * API-only and renders no HTML for auth flows.
     */
    private function configureNotificationUrls(): void
    {
        VerifyEmail::createUrlUsing(fn (User $user): string => EmailVerificationLink::forUser(
            $user,
            now()->addMinutes((int) config('auth.verification.expire', 60)),
        ));

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .'/auth/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]));
    }
}
