<?php

use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stripe webhook — F6
|--------------------------------------------------------------------------
|
| Required from routes/api.php *outside* every group, so this file declares its
| own full path. It gets the `api` middleware group (correlation id, locale,
| SubstituteBindings) and nothing else: no auth:sanctum, because the caller is
| Stripe, and no SetTenantContext, because the tenant is resolved from the
| payload rather than from a token.
|
| Not on `throttle:public` (30/min/IP). That limiter exists to slow down
| anonymous humans; a provider manages its own retry pace and a burst of
| genuine events during a billing run would be silently dropped. The inline
| limit below is a ceiling against a flood, set far above any plausible
| delivery rate, and a rejected request is one the provider retries.
|
*/

Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('webhooks.stripe');
