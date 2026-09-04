<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Middleware\EnforceTokenAbility;
use App\Http\Middleware\QuotaRemainingHeader;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

// Unversioned liveness probe. Intentionally outside /api/v1: it must keep
// answering even while the versioned surface is being changed.
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'backend',
        'time' => now()->toIso8601String(),
    ]);
});

// The stack every authenticated /api/v1 route shares. Declared once so the
// "session" group and the tenant surface below cannot drift apart on it.
$authenticated = [
    'auth:sanctum',
    'throttle:api',
    // Default deny for API keys. Every authenticated route is session-only
    // unless EnforceTokenAbility::MACHINE_ROUTES names it, so a route added
    // later is closed to machine credentials until somebody decides otherwise.
    EnforceTokenAbility::class,
    SetTenantContext::class,
    QuotaRemainingHeader::class,
];

Route::prefix('v1')->name('api.v1.')->group(function () use ($authenticated) {

    Route::middleware('throttle:public')->name('auth.')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login')
            ->name('login');

        Route::post('forgot-password', [PasswordController::class, 'forgot'])
            ->middleware('throttle:auth-login')
            ->name('forgot-password');

        Route::post('reset-password', [PasswordController::class, 'reset'])
            ->name('reset-password');

        // Named because the verification signature is generated against it.
        Route::post('email/verify', [EmailVerificationController::class, 'verify'])
            ->name('email.verify');

        // The second step of a login (docs/contracts/w10-two-factor.md).
        //
        // Public on purpose, and it is the only route in the application that
        // authenticates a bearer token without `auth:sanctum`. The caller holds
        // a challenge token, which carries `TokenAbility::CHALLENGE` and
        // nothing else, and EnforceTokenAbility refuses that ability on every
        // route behind the guard - so putting this one behind the guard too
        // would make the flow unreachable by construction. The controller
        // resolves the token itself and repeats the two checks Sanctum's guard
        // would have made.
        //
        // `throttle:public` (30/min/IP) is inherited from the group and bounds
        // how fast anyone can knock. It is not what bounds guessing against a
        // *single* account: that is the per-token attempt counter in
        // App\Support\Auth\TwoFactorChallenge, because an attacker holding
        // the password can spread six-digit guesses across as many addresses as
        // they like without ever meeting an IP limiter.
        Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])
            ->name('two-factor.challenge');
    });

    // Accepting a team invitation (docs/contracts/settings-api.md section 3a).
    //
    // Declared here, beside the auth block, rather than in routes/api/: every
    // file in that directory is required inside the authenticated + `verified`
    // group, and the recipient of an invitation has no account at all — let
    // alone a verified one. These two and the auth block are the only
    // unauthenticated routes under /api/v1.
    //
    // The token in the path is the credential, so it gets `throttle:public`
    // (30/min/IP) like every other public door: it is a 48-character random
    // string looked up by SHA-256, and the limiter is what makes walking that
    // space pointless rather than merely expensive.
    //
    // {token} is constrained to the alphabet Str::random() produces. Without
    // it, a path segment containing a slash or a dot would miss the route and
    // answer with a different-shaped 404 than an unknown token does — and the
    // whole point of section 3a is that every failure looks the same.
    Route::middleware('throttle:public')->name('invitations.')->group(function () {
        Route::get('invitations/{token}', [InvitationController::class, 'show'])
            ->whereAlphaNumeric('token')
            ->name('show');

        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
            ->whereAlphaNumeric('token')
            ->name('accept');
    });

    // Authenticated but deliberately *not* behind `verified`.
    //
    // These are the routes an unverified session must still reach, or the SPA
    // cannot render the "check your inbox" state it was sent to (contract
    // section 5): it needs /auth/me to know who it is, /auth/email/resend to
    // send another link, and /auth/logout to get out.
    //
    // Session and account lifecycle sit here for the same reason. Revoking a
    // stolen device token must not require a mailbox the user may have lost
    // control of, and gating the right-to-erasure endpoint (spec 8, KVKK/GDPR)
    // behind verification would let an unverified account become undeletable —
    // exactly the data that ought to be easiest to erase.
    Route::middleware($authenticated)->group(function () {
        Route::name('auth.')->prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::get('me', [AuthController::class, 'me'])->name('me');

            Route::post('email/resend', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,60')
                ->name('email.resend');

            // Device-based token revocation (spec 8). {token} is an id, not a
            // bound model, for the same reason the domain routes below use ids:
            // SubstituteBindings runs ahead of SetTenantContext.
            Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');

            Route::delete('tokens/{token}', [TokenController::class, 'destroy'])
                ->whereNumber('token')
                ->name('tokens.destroy');

            // Two-factor enrolment and teardown (docs/contracts/w10-two-factor.md).
            //
            // Here rather than behind `verified`, for the same reason
            // /auth/tokens and DELETE /account are here: this is account
            // security lifecycle, and making it wait on a mailbox gets the
            // dependency backwards. A user who suspects their password is out
            // must be able to add a second factor now, and one who has lost the
            // authenticator must be able to remove it now - neither should
            // hinge on an inbox they may not currently control. The challenge
            // route above is public in any case, so a confirmed user can always
            // finish a login regardless of verification state.
            Route::post('two-factor', [TwoFactorController::class, 'store'])
                ->name('two-factor.store');

            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])
                ->name('two-factor.confirm');

            Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
                ->name('two-factor.recovery-codes');

            Route::delete('two-factor', [TwoFactorController::class, 'destroy'])
                ->name('two-factor.destroy');
        });

        // Right to erasure. No id in the path on purpose — the company is read
        // off the authenticated user, so there is no cross-tenant request to
        // reject in the first place (invariant I1 by construction).
        Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');
    });

    // Authenticated tenant surface. Each wave-2 phase owns exactly one file in
    // routes/api/, so parallel tracks never edit the same route file — this
    // split exists for that reason and for no other. Requiring them inside the
    // group means a domain file cannot accidentally diverge on the middleware
    // stack, because it never declares one.
    //
    // `verified` is applied here and nowhere else (spec 7.1). Everything a
    // tenant does with its data — integrations, feedback, billing — is behind a
    // confirmed mailbox; the session routes above are the deliberate exception.
    Route::middleware([...$authenticated, 'verified'])->group(function () {
        foreach (glob(__DIR__.'/api/*.php') ?: [] as $domainRoutes) {
            require $domainRoutes;
        }
    });
});

// Unauthenticated callbacks: payment provider webhooks. Outside /api/v1 and
// outside auth:sanctum by necessity — the caller is Stripe or Iyzico, not a
// tenant. Signature verification is what authenticates these (spec 7.6), and
// each provider owns its own file here for the same no-collision reason.
foreach (glob(__DIR__.'/api/public/*.php') ?: [] as $publicRoutes) {
    require $publicRoutes;
}
