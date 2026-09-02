<?php

use App\Events\FeedbackIngested;
use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorHealth;
use App\Support\Connectors\ConnectorItem;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\ConnectorPage;
use App\Support\Connectors\IntegrationSyncLock;
use App\Support\Connectors\PlatformConnector;
use App\Support\Connectors\SyncCursor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| The ingestion pipeline, end to end, against the credential-free connector
|--------------------------------------------------------------------------
*/

/** A connector that never admits the stream ended — the runner's safety net. */
final class NeverEndingConnector implements PlatformConnector
{
    public int $calls = 0;

    public function __construct(private readonly ConnectorLimits $limits) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $this->calls++;

        return new ConnectorPage(
            items: [new ConnectorItem('endless-'.$this->calls, null, 'body', null, null, null, [])],
            hasMore: true,
            nextCursor: '{"page":'.($this->calls + 1).'}',
        );
    }

    public function limits(): ConnectorLimits
    {
        return $this->limits;
    }

    public function healthCheck(): ConnectorHealth
    {
        return ConnectorHealth::ok();
    }
}

/** A connector that answers empty pages forever. */
final class AlwaysEmptyConnector implements PlatformConnector
{
    public int $calls = 0;

    public function __construct(private readonly ConnectorLimits $limits) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $this->calls++;

        return new ConnectorPage(items: [], hasMore: true, nextCursor: '{"page":'.($this->calls + 1).'}');
    }

    public function limits(): ConnectorLimits
    {
        return $this->limits;
    }

    public function healthCheck(): ConnectorHealth
    {
        return ConnectorHealth::ok();
    }
}

/** A connector that fails the way a real one would. */
final class BrokenConnector implements PlatformConnector
{
    public function __construct(private readonly ConnectorFailure $failure) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        throw ConnectorException::of($this->failure, new RuntimeException(
            'HTTP 401 rejecting api_key=super-secret-value for Authorization: Bearer super-secret-value',
        ));
    }

    public function limits(): ConnectorLimits
    {
        return new ConnectorLimits(10, 3);
    }

    public function healthCheck(): ConnectorHealth
    {
        return ConnectorHealth::failing($this->failure);
    }
}

final class StubConnectorFactory extends ConnectorFactory
{
    public function __construct(private readonly PlatformConnector $connector) {}

    public function for(Integration $integration): PlatformConnector
    {
        return $this->connector;
    }
}

function useConnector(PlatformConnector $connector): void
{
    app()->instance(ConnectorFactory::class, new StubConnectorFactory($connector));
}

/** @return array{0: Company, 1: Integration} */
function fixtureIntegration(array $attributes = []): array
{
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'fixture',
        'settings' => ['fixture_set' => 'default'],
        'credentials' => ['api_key' => 'super-secret-value'],
    ], $attributes));

    return [$company, $integration];
}

function runFetch(Company $company, Integration $integration): void
{
    FetchFeedbackJob::dispatchSync($company->id, $integration->id);
}

/**
 * Run the job without the queue wrapper.
 *
 * SyncQueue catches anything thrown out of a job and immediately calls failed()
 * on it, which is the *exhausted retries* path. A transient failure that is
 * meant to be retried has to be observed before that happens, so these tests
 * invoke handle() directly.
 */
function runFetchDirect(Company $company, Integration $integration): void
{
    (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
}

function fixtureItemCount(): int
{
    $total = 0;

    foreach (['page-1.json', 'page-2.json'] as $file) {
        $total += count(json_decode(PlatformFixture::raw('fixture', 'default/'.$file), true));
    }

    return $total;
}

beforeEach(function () {
    RateLimiter::clear('connector:fixture');
});

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('walks every page and stores every item', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);

    $rows = asTenant($company, fn () => Feedback::query()->where('integration_id', $integration->id)->get());

    expect($rows)->toHaveCount(fixtureItemCount())
        ->and($rows->pluck('analysis_status')->unique()->values()->all())->toBe([Feedback::STATUS_PENDING])
        ->and($rows->pluck('company_id')->unique()->values()->all())->toBe([$company->id]);
});

it('fires FeedbackIngested once per newly created row', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);

    Event::assertDispatchedTimes(FeedbackIngested::class, fixtureItemCount());

    $ids = asTenant($company, fn () => Feedback::query()->pluck('id')->all());

    foreach ($ids as $id) {
        Event::assertDispatched(
            FeedbackIngested::class,
            fn (FeedbackIngested $event) => $event->feedbackId === $id && $event->companyId === $company->id,
        );
    }
});

it('does not dispatch the analysis job itself', function () {
    // F5 owns AnalyzeFeedbackJob and subscribes to the event. Ingestion knows
    // nothing about it; that split is what lets both phases be built at once.
    expect(file_get_contents(app_path('Jobs/FetchFeedbackJob.php')))->not->toContain('AnalyzeFeedbackJob')
        ->and(file_get_contents(app_path('Support/Connectors/IngestionRunner.php')))->not->toContain('AnalyzeFeedbackJob');
});

it('advances the cursor and stamps the sync time', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));
    $cursor = SyncCursor::decode($reloaded->sync_cursor);

    expect($reloaded->sync_cursor)->not->toBeNull()
        ->and($reloaded->last_synced_at)->not->toBeNull()
        ->and($reloaded->sync_error)->toBeNull()
        // Rewound for the next run: the feed is newest-first, so the next pass
        // starts at the top and stops at this watermark.
        ->and($cursor->page)->toBe(1)
        ->and($cursor->watermark)->not->toBeNull();
});

it('masks an email address before the body is stored', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);

    $bodies = asTenant($company, fn () => Feedback::query()->pluck('body')->implode("\n"));

    expect($bodies)->toContain('[email]')
        ->and($bodies)->not->toContain('reviewer@example.test');
});

it('releases the sync lock when the run succeeds', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    app(IntegrationSyncLock::class)->acquire($integration->id);
    runFetch($company, $integration);

    expect(app(IntegrationSyncLock::class)->isHeld($integration->id))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same comment is never ingested, or analysed, twice
|--------------------------------------------------------------------------
*/

it('creates nothing and fires nothing on a re-fetch of the same page', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the connector genuinely serves the same items again.
    // Without this the watermark would filter them out and the unique index
    // would never be exercised at all.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    Event::fake([FeedbackIngested::class]);
    runFetch($company, $integration);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst);
    Event::assertNotDispatched(FeedbackIngested::class);
});

it('keeps one row per external id when a run repeats', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    foreach (range(1, 3) as $ignored) {
        asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
            ->forceFill(['sync_cursor' => null])->save());
        runFetch($company, $integration);
    }

    $externalIds = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());

    expect($externalIds)->toHaveCount(fixtureItemCount())
        ->and(array_unique($externalIds))->toHaveCount(fixtureItemCount());
});

it('never touches a row another integration already owns', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();
    $other = Integration::factory()->for($company)->create(['platform' => 'fixture']);

    // The same external id under a different integration is a different comment.
    Feedback::factory()->for($company)->for($other)->create(['external_id' => 'fixture-0001']);

    runFetch($company, $integration);

    $count = asTenant($company, fn () => Feedback::query()->where('external_id', 'fixture-0001')->count());

    expect($count)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1)
|--------------------------------------------------------------------------
*/

it('stops at the watermark on the second run instead of re-reading everything', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);
    $cursorAfterFirst = asTenant($company, fn () => Integration::query()->findOrFail($integration->id))->sync_cursor;

    Event::fake([FeedbackIngested::class]);
    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    Event::assertNotDispatched(FeedbackIngested::class);

    expect(SyncCursor::decode($reloaded->sync_cursor)->watermark)
        ->toBe(SyncCursor::decode($cursorAfterFirst)->watermark);
});

/*
|--------------------------------------------------------------------------
| The runner's safety net — a connector cannot loop forever
|--------------------------------------------------------------------------
*/

it('stops at the page ceiling however loudly the connector says there is more', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();
    $connector = new NeverEndingConnector(new ConnectorLimits(4, 3));
    useConnector($connector);
    Log::spy();

    runFetch($company, $integration);

    expect($connector->calls)->toBe(4)
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(4);

    Log::shouldHaveReceived('warning')->once();
});

it('stops after a streak of empty pages', function () {
    [$company, $integration] = fixtureIntegration();
    $connector = new AlwaysEmptyConnector(new ConnectorLimits(50, 3));
    useConnector($connector);
    Log::spy();

    runFetch($company, $integration);

    expect($connector->calls)->toBe(3)
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — a failure never carries credential material anywhere
|--------------------------------------------------------------------------
*/

it('records a safe sync_error and nothing of the credential', function () {
    [$company, $integration] = fixtureIntegration();
    useConnector(new BrokenConnector(ConnectorFailure::InvalidCredentials));

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
        ->and($reloaded->sync_error)->not->toContain('super-secret-value')
        ->and($reloaded->sync_error)->not->toContain('api_key')
        ->and($reloaded->sync_error)->not->toContain('Bearer')
        ->and($reloaded->sync_error)->not->toContain('401');
});

it('logs the failure by id and reason, never by payload', function () {
    Log::spy();
    [$company, $integration] = fixtureIntegration();
    useConnector(new BrokenConnector(ConnectorFailure::InvalidCredentials));

    runFetch($company, $integration);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $serialized = $message.json_encode($context);

        return ! str_contains($serialized, 'super-secret-value')
            && ! str_contains($serialized, 'Bearer')
            && ! array_key_exists('credentials', $context)
            && array_key_exists('integration_id', $context);
    })->once();
});

it('does not mark the integration broken when the platform is merely rate limiting', function () {
    [$company, $integration] = fixtureIntegration();
    useConnector(new BrokenConnector(ConnectorFailure::RateLimited));

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('active')
        ->and($reloaded->sync_error)->toBeNull();
});

it('records the safe message for every terminal failure reason', function (ConnectorFailure $failure) {
    [$company, $integration] = fixtureIntegration();
    useConnector(new BrokenConnector($failure));

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe($failure->safeMessage());
})->with([
    ConnectorFailure::DepthLimitExceeded,
    ConnectorFailure::MalformedResponse,
    ConnectorFailure::Misconfigured,
]);

it('leaves the cursor where it was when a page fails', function () {
    [$company, $integration] = fixtureIntegration(['sync_cursor' => '{"page":1,"watermark":"2026-01-01T00:00:00+00:00"}']);
    useConnector(new BrokenConnector(ConnectorFailure::DepthLimitExceeded));

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_cursor)->toBe('{"page":1,"watermark":"2026-01-01T00:00:00+00:00"}');
});

it('clears a previous error once a run succeeds', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration(['status' => 'error', 'sync_error' => 'Something went wrong earlier.']);

    runFetch($company, $integration);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('active')
        ->and($reloaded->sync_error)->toBeNull();
});

it('does not un-pause a paused integration that syncs successfully', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration(['status' => 'paused']);

    runFetch($company, $integration);

    expect(asTenant($company, fn () => Integration::query()->findOrFail($integration->id))->status)
        ->toBe('paused');
});

/*
|--------------------------------------------------------------------------
| Job mechanics
|--------------------------------------------------------------------------
*/

it('carries the tenant explicitly and restores the previous context', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    runFetch($company, $integration);

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('does nothing when the integration was deleted between dispatch and execution', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();
    $id = $integration->id;

    app(IntegrationSyncLock::class)->acquire($id);
    asTenant($company, fn () => Integration::query()->findOrFail($id)->delete());

    FetchFeedbackJob::dispatchSync($company->id, $id);

    expect(app(IntegrationSyncLock::class)->isHeld($id))->toBeFalse();
    Event::assertNotDispatched(FeedbackIngested::class);
});

it('skips the run when the per-platform throttle is exhausted', function () {
    Event::fake([FeedbackIngested::class]);
    [$company, $integration] = fixtureIntegration();

    $allowed = app(ConnectorFactory::class)->rateLimit('fixture')['max_attempts'];

    foreach (range(1, $allowed) as $ignored) {
        RateLimiter::hit('connector:fixture', 60);
    }

    runFetch($company, $integration);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe(0);
    Event::assertNotDispatched(FeedbackIngested::class);
});

it('marks the integration broken when the retries are exhausted', function () {
    [$company, $integration] = fixtureIntegration();
    app(IntegrationSyncLock::class)->acquire($integration->id);

    app(TenantContext::class)->runFor($company->id, function () use ($company, $integration) {
        (new FetchFeedbackJob($company->id, $integration->id))->failed(new RuntimeException('gave up'));
    });

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe('Sync failed after repeated attempts.')
        ->and(app(IntegrationSyncLock::class)->isHeld($integration->id))->toBeFalse();
});

it('rethrows a transient failure so the queue can retry it', function () {
    [$company, $integration] = fixtureIntegration();
    useConnector(new BrokenConnector(ConnectorFailure::Unreachable));

    // The protocol is that the dispatch site acquires and the job releases, so
    // the lock has to be taken here for the assertion below to mean anything:
    // runFetchDirect() bypasses the queue, and without this the final
    // expectation would read false-is-not-true rather than proving the job left
    // the lock alone on the retry path.
    app(IntegrationSyncLock::class)->acquire($integration->id);

    // Terminal failures are swallowed after being recorded — retrying them only
    // delays the error the user needs to see. A transient one is thrown back at
    // the queue so the backoff schedule applies.
    expect(fn () => runFetchDirect($company, $integration))->toThrow(ConnectorException::class);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe(ConnectorFailure::Unreachable->safeMessage())
        // Still held: the same run is coming back, and a second dispatch would
        // only add load to a platform that is already not answering.
        ->and(app(IntegrationSyncLock::class)->isHeld($integration->id))->toBeTrue();
});

it('declares the retry and backoff policy the spec asks for', function () {
    $job = new FetchFeedbackJob(1, 1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 60, 300, 900]);
});
