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

/*
|--------------------------------------------------------------------------
| Expired token pruning
|--------------------------------------------------------------------------
|
| `personal_access_tokens` only ever grew. Sanctum ships the command and this
| application never scheduled it, so every row a login has written since F2 is
| still there — dead, but still looked up by hash on every bearer request that
| happens to collide on the prefix.
|
| W10 did not create the problem; it raised the rate. A correct password from a
| user with a second factor now writes a five-minute challenge row, and that row
| is deleted on success and on exhausting the attempt budget — but not when the
| user simply abandons the flow, which is the ordinary outcome of "I opened
| login on the wrong device". Those accumulate one per abandoned attempt.
|
| The command is not limited to challenge tokens and the retention is chosen
| with that in mind. It deletes every row whose `expires_at` is more than
| --hours in the past — expired device sessions and expired API keys as well as
| challenge tokens — and, because `sanctum.expiration` is set, also every row
| older than that ceiling plus the same window, which is what finally reaches
| the legacy `['*']` rows that carry no `expires_at` at all.
|
| 24 hours rather than 0: a token that stopped working an hour ago is evidence.
| "Why did my session end?" and "which credential did the attacker use?" are
| both answered by a row that is expired and still present, and a prune that ran
| the moment expiry passed would delete the record mid-incident. A day is long
| enough to look, short enough that the table does not carry a year of dead rows.
|
*/

Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->daily()
    ->name('auth:prune-expired-tokens')
    ->withoutOverlapping();
