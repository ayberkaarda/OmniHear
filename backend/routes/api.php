<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
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

Route::prefix('v1')->name('api.v1.')->group(function () {

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

    Route::middleware([
        'auth:sanctum',
        'throttle:api',
        SetTenantContext::class,
        QuotaRemainingHeader::class,
    ])->name('auth.')->prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,60')
            ->name('email.resend');
    });

    // Authenticated tenant surface. Each wave-2 phase owns exactly one file in
    // routes/api/, so parallel tracks never edit the same route file — this
    // split exists for that reason and for no other. Requiring them inside the
    // group means a domain file cannot accidentally diverge on the middleware
    // stack, because it never declares one.
    Route::middleware([
        'auth:sanctum',
        'throttle:api',
        SetTenantContext::class,
        QuotaRemainingHeader::class,
    ])->group(function () {
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
