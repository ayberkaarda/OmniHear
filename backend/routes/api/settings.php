<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Settings\ApiKeyController;
use App\Http\Controllers\Api\V1\Settings\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Settings\ProfileController;
use App\Http\Controllers\Api\V1\Settings\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings and in-app notifications — W5
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, which already
| applies the /api/v1 prefix, the api.v1. name prefix and the
| auth:sanctum + throttle:api + SetTenantContext + QuotaRemainingHeader +
| verified stack. Re-declaring any of those here would double them.
|
| Contract: docs/contracts/settings-api.md.
|
| {user} is route-model bound, unlike {integration} and {feedback}. That is
| deliberate and it is safe for a specific reason: User is exempt from
| CompanyScope (authentication has to resolve a user before a tenant exists),
| so binding cannot fail closed the way a scoped model would — and
| SetTenantContext was prepended to $middlewarePriority ahead of
| SubstituteBindings (docs/LESSONS.md), so the tenant is established by the
| time binding runs in any case. Isolation is carried by UserPolicy, which
| denies another company's user *as not found*: 404, never 403 (invariant I1).
|
| The notification id is a UUID, so the route constrains it as one. Without
| that, a non-UUID path segment would reach the uuid column and PostgreSQL
| would answer with a cast error rendered as 500 instead of the 404 it is.
|
*/

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::patch('password', [ProfileController::class, 'updatePassword'])->name('password.update');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');

    // Declared before the {user} routes: "invitations" is not numeric and the
    // parameter is constrained to digits, so the two cannot collide — the order
    // is belt and braces, not the mechanism.
    //
    // The extra limiter is not decoration. This is the only authenticated
    // endpoint that sends mail to an address nobody in the tenant controls, and
    // `throttle:api` alone would let one admin mail 120 strangers a minute. The
    // ceiling is per company and per day; see AppServiceProvider.
    Route::post('team/invitations', [TeamController::class, 'invite'])
        ->middleware('throttle:team-invitations')
        ->name('team.invitations.store');

    Route::patch('team/{user}', [TeamController::class, 'update'])
        ->whereNumber('user')
        ->name('team.update');

    Route::delete('team/{user}', [TeamController::class, 'destroy'])
        ->whereNumber('user')
        ->name('team.destroy');

    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy'])
        ->whereNumber('id')
        ->name('api-keys.destroy');

    Route::get('notifications', [NotificationPreferenceController::class, 'show'])
        ->name('notifications.show');
    Route::patch('notifications', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.update');
});

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');

Route::post('notifications/{id}/read', [NotificationController::class, 'read'])
    ->whereUuid('id')
    ->name('notifications.read');
