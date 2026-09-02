<?php

use App\Jobs\FetchFeedbackJob;
use App\Models\Integration;
use App\Models\Scopes\CompanyScope;
use App\Support\Connectors\IntegrationSyncLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Ingestion (spec 6.1)
|--------------------------------------------------------------------------
|
| Every five minutes, one FetchFeedbackJob per active integration.
|
| The sweep itself runs outside any tenant — it is the one place that
| legitimately looks across all of them — and each job then carries its own
| company id into TenantContext::runFor. The alternative, iterating companies
| and setting a context per company, would be the same query with more moving
| parts and one more place to get the scope wrong.
|
| Dispatch is gated on the sync lock so a slow or repeatedly retried
| integration cannot accumulate a queue of duplicate runs every five minutes.
| The job is what releases it.
|
*/

Schedule::call(function (IntegrationSyncLock $lock): void {
    $chunk = max(1, (int) config('connectors.schedule.chunk', 100));

    Integration::query()
        ->withoutGlobalScope(CompanyScope::class) // tenant-scope: bypass-ok the scheduler is not a tenant; every row dispatches a job that re-enters its own company's context
        ->where('status', 'active')
        ->whereIn('platform', array_keys((array) config('connectors.platforms', [])))
        ->orderBy('id')
        ->chunkById($chunk, function (Collection $integrations) use ($lock): void {
            foreach ($integrations as $integration) {
                $integrationId = (int) $integration->id;

                if ($lock->acquire($integrationId)) {
                    FetchFeedbackJob::dispatch((int) $integration->company_id, $integrationId);
                }
            }
        });
})
    ->everyFiveMinutes()
    ->name('ingestion:fetch-feedback')
    ->withoutOverlapping();
