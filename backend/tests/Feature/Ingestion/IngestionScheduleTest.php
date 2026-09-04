<?php

use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Integration;
use App\Support\Connectors\IntegrationSyncLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The five-minute sweep (spec 6.1)
|--------------------------------------------------------------------------
*/

function ingestionSweep(): ScheduledEvent
{
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => $event->description === 'ingestion:fetch-feedback');

    expect($events)->toHaveCount(1);

    return $events->first();
}

function runIngestionSweep(): void
{
    // The registered event itself, not a copy of its body: a test that
    // reimplemented the sweep would keep passing after the real one broke.
    ingestionSweep()->run(app());
}

it('is registered to run every five minutes', function () {
    expect(ingestionSweep()->expression)->toBe('*/5 * * * *');
});

it('will not overlap with a run that is still going', function () {
    expect(ingestionSweep()->withoutOverlapping)->toBeTrue();
});

it('dispatches one job per active integration, across every tenant', function () {
    Queue::fake();

    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $a = Integration::factory()->for($first)->create(['platform' => 'fixture']);
    $b = Integration::factory()->for($second)->create(['platform' => 'appstore', 'settings' => ['app_id' => '1', 'country' => 'tr']]);

    runIngestionSweep();

    Queue::assertPushed(FetchFeedbackJob::class, 2);
    Queue::assertPushed(FetchFeedbackJob::class, fn (FetchFeedbackJob $job) => $job->companyId === $first->id && $job->integrationId === $a->id);
    Queue::assertPushed(FetchFeedbackJob::class, fn (FetchFeedbackJob $job) => $job->companyId === $second->id && $job->integrationId === $b->id);
});

it('leaves paused and failing integrations alone', function () {
    Queue::fake();

    $company = Company::factory()->create();
    Integration::factory()->for($company)->paused()->create(['platform' => 'fixture']);
    Integration::factory()->for($company)->errored()->create(['platform' => 'fixture']);

    runIngestionSweep();

    Queue::assertNothingPushed();
});

it('skips a platform that has no connector yet', function () {
    Queue::fake();

    // social, not googleplay or email: both gained a connector when their
    // respective waves wired them into config/connectors.php, so the same
    // integration row would now match the scheduler's
    // whereIn(array_keys(config('connectors.platforms'))) filter and this test
    // would fail for real, not silently — but a platform that stops meaning
    // "unimplemented" the moment someone lands it is still the wrong example
    // to keep. social is the one platform docs/contracts/backend-core.md
    // section 1 lists that still has no connector; assert that here rather
    // than assume it, so this goes red with a clear reason instead of silently
    // passing for the wrong one if social is ever wired.
    expect(config('connectors.platforms.social'))->toBeNull();

    $company = Company::factory()->create();
    Integration::factory()->for($company)->create(['platform' => 'social']);

    runIngestionSweep();

    Queue::assertNothingPushed();
});

it('sweeps an active integration of every platform that has a connector', function () {
    Queue::fake();

    $company = Company::factory()->create();

    Integration::factory()->for($company)->create(['platform' => 'fixture']);
    Integration::factory()->for($company)->create([
        'platform' => 'appstore',
        'settings' => ['app_id' => '999999999', 'country' => 'tr'],
    ]);
    Integration::factory()->for($company)->create([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ]);

    runIngestionSweep();

    Queue::assertPushed(FetchFeedbackJob::class, 3);
});

it('does not queue a second run for an integration that is already syncing', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create(['platform' => 'fixture']);

    app(IntegrationSyncLock::class)->acquire($integration->id);

    runIngestionSweep();

    Queue::assertNothingPushed();
});

it('runs outside any tenant context and leaves none behind', function () {
    Queue::fake();

    Integration::factory()->for(Company::factory()->create())->create(['platform' => 'fixture']);

    runIngestionSweep();

    expect(app(TenantContext::class)->id())->toBeNull();
});
