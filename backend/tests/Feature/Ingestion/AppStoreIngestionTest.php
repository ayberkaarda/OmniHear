<?php

use App\Events\FeedbackIngested;
use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\SyncCursor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| The App Store feed, end to end, against the responses recorded on 2026-09-02
|--------------------------------------------------------------------------
|
| The three behaviours below are measured facts (PROGRESS "Verified facts"), and
| each has a test here rather than a comment. Expectations are derived from the
| fixtures at run time: the recorded review text is being replaced with
| synthetic values, and an assertion on a particular review would break on that
| swap while proving nothing.
|
*/

/** @return array{0: Company, 1: Integration} */
function appStoreIntegration(array $attributes = []): array
{
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'appstore',
        'settings' => ['app_id' => '324684580', 'country' => 'tr'],
        'credentials' => [],
    ], $attributes));

    return [$company, $integration];
}

function appStoreBody(string $file): string
{
    return PlatformFixture::raw('appstore', $file);
}

/**
 * @return list<string>
 */
function externalIdsIn(string $file): array
{
    return array_map(
        static fn (array $entry): string => $entry['id']['label'],
        PlatformFixture::appStoreEntries($file),
    );
}

beforeEach(function () {
    RateLimiter::clear('connector:appstore');
    Event::fake([FeedbackIngested::class]);
});

/*
|--------------------------------------------------------------------------
| Behaviour 3 — the feed needs no credentials
|--------------------------------------------------------------------------
*/

it('ingests a real recorded page with no credentials configured', function () {
    Http::fake(['*' => Http::response(appStoreBody('page-full.json'), 200)]);
    [$company, $integration] = appStoreIntegration(['credentials' => []]);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $expected = externalIdsIn('page-full.json');
    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());

    sort($expected);
    sort($stored);

    expect($stored)->toBe($expected)
        ->and($stored)->not->toBeEmpty();

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('stores the mapped fields of the recorded entries', function () {
    Http::fake(['*' => Http::response(appStoreBody('page-full.json'), 200)]);
    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $entry = PlatformFixture::appStoreEntries('page-full.json')[0];

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $entry['id']['label'])
        ->firstOrFail());

    expect($row->author)->toBe($entry['author']['name']['label'])
        ->and($row->body)->toBe($entry['content']['label'])
        ->and($row->source_url)->toBe($entry['link']['attributes']['href'])
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        ->and($row->published_at?->equalTo(SyncCursor::parse($entry['updated']['label'])))->toBeTrue()
        ->and($row->raw_payload['id']['label'])->toBe($entry['id']['label']);
});

/*
|--------------------------------------------------------------------------
| Behaviour 1 — page depth is capped at 10
|--------------------------------------------------------------------------
*/

it('never walks past ten pages even when every page claims there is more', function () {
    // Each page carries one entry newer than the last, so the watermark never
    // catches up and the *only* thing that can end this run is the documented
    // depth ceiling. The entry is a copy of a recorded one, so the shape under
    // test is still the real feed's.
    $template = PlatformFixture::appStoreEntries('page-full.json')[0];
    $served = 0;

    Http::fake(function () use ($template, &$served) {
        $served++;
        $entry = $template;
        $entry['id']['label'] = 'synthetic-'.$served;
        $entry['updated']['label'] = CarbonImmutable::parse('2026-09-01T00:00:00+00:00')
            ->addDays($served)
            ->toIso8601String();

        return Http::response(json_encode(['feed' => ['entry' => [$entry]]]), 200);
    });

    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect($served)->toBe(10);

    $pagesRequested = Http::recorded()
        ->map(fn (array $pair) => (int) str($pair[0]->url())->after('/page=')->before('/')->toString())
        ->all();

    expect($pagesRequested)->toBe(range(1, 10))
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(10);
});

it('clamps a cursor that somehow points past the depth limit', function () {
    // page=11 is a documented HTTP 400. The request is never issued: the cursor
    // is clamped to the last reachable page instead.
    Http::fake(['*' => Http::response(appStoreBody('page-full.json'), 200)]);
    [$company, $integration] = appStoreIntegration(['sync_cursor' => '{"page":11}']);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/page=10/'));
});

it('stops the run and keeps the cursor when the feed refuses the depth', function () {
    // The recorded 400 body is gzip'd plain text. Reading the status code is
    // the point: parsing the body would look like a corrupt payload.
    Http::fake(['*' => Http::response(appStoreBody('page-depth-exceeded.txt'), 400)]);
    [$company, $integration] = appStoreIntegration(['sync_cursor' => '{"page":1,"watermark":"2026-01-01T00:00:00+00:00"}']);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe('The platform refused the requested page depth.')
        ->and($reloaded->sync_cursor)->toBe('{"page":1,"watermark":"2026-01-01T00:00:00+00:00"}')
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Behaviour 2 — pages come back empty intermittently
|--------------------------------------------------------------------------
*/

it('keeps going past an empty page and ingests what is behind it', function () {
    // Measured: page=1 returned 0 entries once and 50 on the five retries that
    // followed. If an empty page ended the run, every review on page 2 would be
    // lost silently — no error, no retry, just missing data.
    Http::fake([
        '*page=1/*' => Http::response(appStoreBody('page-empty-transient.json'), 200),
        '*' => Http::response(appStoreBody('page-full.json'), 200),
    ]);
    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $expected = count(externalIdsIn('page-full.json'));

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($expected);
    Event::assertDispatchedTimes(FeedbackIngested::class, $expected);
});

it('does not treat an empty page as the end of the stream', function () {
    Http::fake([
        '*page=1/*' => Http::response(appStoreBody('page-empty-transient.json'), 200),
        '*' => Http::response(appStoreBody('page-full.json'), 200),
    ]);
    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(Http::recorded()->count())->toBeGreaterThan(1);
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — a full re-scan is forbidden
|--------------------------------------------------------------------------
*/

it('reads one page on the second run instead of the whole history', function () {
    // Run one: page 1 is new, page 2 repeats it, so the watermark is reached on
    // page 2 and the run stops there.
    Http::fake([
        '*page=1/*' => Http::response(appStoreBody('page-full-2.json'), 200),
        '*' => Http::response(appStoreBody('page-full.json'), 200),
    ]);
    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    $firstRunRequests = Http::recorded()->count();

    // Run two: nothing new has arrived, so page 1 alone catches up with the
    // watermark and the run ends there.
    Http::fake([
        '*page=1/*' => Http::response(appStoreBody('page-full-2.json'), 200),
        '*' => Http::response(appStoreBody('page-full.json'), 200),
    ]);
    Event::fake([FeedbackIngested::class]);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(Http::recorded()->count())->toBe(1)
        ->and($firstRunRequests)->toBeGreaterThan(1);

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('creates no duplicate row when the same page is served twice', function () {
    Http::fake(['*' => Http::response(appStoreBody('page-full.json'), 200)]);
    [$company, $integration] = appStoreIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the feed genuinely serves the same reviews again and
    // the unique index, rather than the watermark, is what stops them.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    Event::fake([FeedbackIngested::class]);
    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst);
    Event::assertNotDispatched(FeedbackIngested::class);
});

/*
|--------------------------------------------------------------------------
| Failure surface
|--------------------------------------------------------------------------
*/

it('records a safe error when the feed is unreachable', function () {
    Http::fake(['*' => Http::response('upstream is down', 503)]);
    [$company, $integration] = appStoreIntegration();

    // handle() directly rather than through the sync queue: SyncQueue catches
    // anything thrown and immediately runs the exhausted-retries path, which
    // would overwrite the message this test is about.
    try {
        (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
    } catch (Throwable) {
        // Transient, so it is rethrown for the queue to retry. The recorded
        // state is what matters here.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe('The platform could not be reached.')
        ->and($reloaded->sync_error)->not->toContain('upstream is down');
});
