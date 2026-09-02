<?php

use App\Http\Controllers\Api\V1\BillingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing — F6/F7
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, which already
| applies the v1 prefix, the `api.v1.` name prefix, auth:sanctum, throttle:api,
| SetTenantContext and QuotaRemainingHeader. Re-declaring any of those here
| would double them, so this file declares routes and nothing else.
|
*/

Route::prefix('billing')->name('billing.')->group(function () {
    Route::get('subscription', [BillingController::class, 'show'])->name('subscription');

    Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
});
