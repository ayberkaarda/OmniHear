<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\PlatformController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations (F4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside a group that already applies the /api/v1
| prefix, the api.v1. name prefix and the auth:sanctum + throttle:api +
| SetTenantContext + QuotaRemainingHeader stack. Re-declaring any of those here
| would double them.
|
| {integration} is an id, not a bound model. SubstituteBindings is in Laravel's
| $middlewarePriority list and SetTenantContext is not, so an implicitly bound
| model would be queried before the tenant context exists — and CompanyScope
| fails closed, by design. The controller resolves the id instead, after the
| middleware stack, so the global scope is what turns another tenant's id into
| a 404 (invariant I1). whereNumber keeps a non-numeric id from reaching the
| bigint column as a cast error.
|
*/

// The connector registry, served so the integration form does not have to
// hand-copy config/connectors.php (docs/contracts/settings-api.md section 5).
// Declared before the apiResource: `platforms` is not numeric and
// {integration} is constrained to digits, so the two cannot collide — the
// order is belt and braces, not the mechanism.
Route::get('integrations/platforms', [PlatformController::class, 'index'])
    ->name('integrations.platforms');

Route::apiResource('integrations', IntegrationController::class)
    ->names('integrations')
    ->whereNumber('integration');

Route::post('integrations/{integration}/sync', [IntegrationController::class, 'sync'])
    ->whereNumber('integration')
    ->name('integrations.sync');
