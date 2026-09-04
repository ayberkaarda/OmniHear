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

    // not-a-platform, not social: social gained a connector in W12, the sixth
    // and last channel of spec §2 — every platform docs/contracts/backend-core.md
    // section 1 lists (Integration::PLATFORMS) now has a connector, so there
    // is no longer any documented-but-unwired platform left to name here. The
    // scheduler behaviour this test protects — skipping an integration row
    // whose platform has no entry in config('connectors.platforms') — is
    // still real, so the case moves to a value that names no real platform at
    // all rather than an unwired one, matching
    // tests/Unit/Connectors/ConnectorFactoryTest.php's own 'not-a-platform'.
    // Assert the absence rather than assume it, so this goes red with a clear
    // reason instead of silently passing for the wrong platform if
    // 'not-a-platform' is ever accidentally wired.
    expect(config('connectors.platforms.not-a-platform'))->toBeNull();

    $company = Company::factory()->create();
    Integration::factory()->for($company)->create(['platform' => 'not-a-platform']);

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
