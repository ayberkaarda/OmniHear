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

// The one endpoint in the whole authenticated surface that carries `quota`
// (spec 7.4). It is the only user-triggered route that spends analysis quota:
// FetchFeedbackJob::dispatch appears here and nowhere else, store() starts no
// initial sync, and there is no re-analyse endpoint. Everything else stays
// open on purpose — an exhausted tenant still has to reach the inbox, the KPIs
// and the billing page, because that is where the paywall is rendered, and
// gating the group would hide the wall behind the wall.
//
// This does not weaken spec 7.4's `pending_analysis` accumulation: the
// five-minute scheduler dispatches FetchFeedbackJob without consulting the
// quota and AnalyzeFeedbackJob parks the work, so the backlog still builds.
// The 402 only closes the "give me more work I cannot pay for" button.
Route::post('integrations/{integration}/sync', [IntegrationController::class, 'sync'])
    ->middleware('quota')
    ->whereNumber('integration')
    ->name('integrations.sync');
