<?php

use App\Http\Controllers\Api\Webhooks\IyzicoWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Iyzico webhook — F7
|--------------------------------------------------------------------------
|
| Same shape and the same reasoning as routes/api/public/stripe.php.
|
| The throttle ceiling matters more here: iyzico gives up after three delivery
| attempts (PROGRESS, verified facts 2026-09-02), so a request lost to a rate
| limiter costs a third of the budget for that event.
|
*/

Route::post('webhooks/iyzico', IyzicoWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('webhooks.iyzico');
