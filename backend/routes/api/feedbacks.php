<?php

use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\OverviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| F5 - analysis surface
|--------------------------------------------------------------------------
|
| Required from inside the authenticated /api/v1 group in routes/api.php, which
| already applies the prefix, the name prefix and the middleware stack
| (auth:sanctum, throttle:api, SetTenantContext, QuotaRemainingHeader). This
| file must therefore declare none of them - see routes/api.php.
|
| Read-only, and deliberately not behind the `quota` middleware: when the quota
| is exhausted the SPA still has to load the inbox and the KPIs in order to
| render the paywall over them.
|
*/

Route::get('feedbacks', [FeedbackController::class, 'index'])->name('feedbacks.index');
Route::get('feedbacks/{feedback}', [FeedbackController::class, 'show'])->name('feedbacks.show');

Route::get('overview/kpis', [OverviewController::class, 'kpis'])->name('overview.kpis');
