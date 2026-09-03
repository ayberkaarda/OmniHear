<?php

namespace App\Providers;

use App\Models\Integration;
use App\Models\User;
use App\Observers\IntegrationObserver;
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
        $this->configureAuditing();
    }

    /**
     * Integration lifecycle rows in `audit_logs` (spec 5).
     *
     * Registered as an observer rather than written into IntegrationController,
     * so that every write path to the table leaves the same trail — including
     * the ones later phases add.
     */
    private function configureAuditing(): void
    {
        Integration::observe(IntegrationObserver::class);
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
     * The four limiters of the HTTP contract, section 3, plus the outbound-mail
     * ceiling on invitations.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Keyed by user, and only ever by user. The `?? $request->ip()` branch
        // is UNREACHABLE and is kept only because removing it would leave a
        // null dereference if the ordering below ever changed: `throttle:api`
        // is declared inside the authenticated stack, and priority sorting
        // puts Authenticate ahead of ThrottleRequests, so this closure is
        // never evaluated for a request that has no user. It is not - and
        // never was - the protection an anonymous caller meets; that is
        // App\Http\Middleware\ThrottleFailedAuthentication, prepended to the
        // `api` group in bootstrap/app.php.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Invitations send mail to an address the recipient never asked us to
        // write to, which makes this the one authenticated endpoint that can be
        // turned into a spam cannon. `throttle:api` alone allows 120 a minute
        // from a single admin.
        //
        // Keyed by company, not by user: the abuse is a tenant mailing the
        // world, and an owner who has run the ceiling down must not be able to
        // reset it by inviting a colleague to do the next 50. A company that
        // genuinely onboards more than 50 people in a day waits out the window
        // — nothing is lost, and the refusal is the catalogued 429
        // TOO_MANY_REQUESTS with a Retry-After header, not a new error code.
        RateLimiter::for('team-invitations', fn (Request $request) => Limit::perDay(
            (int) config('registration.invitations_per_day', 50),
        )->by('company:'.($request->user()?->getAttribute('company_id') ?? $request->ip())));
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
