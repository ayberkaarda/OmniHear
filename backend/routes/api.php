<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
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
