<?php

use App\Events\FeedbackIngested;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\SyncCursor;
use App\Support\Connectors\TrustpilotConnector;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| Trustpilot, end to end — IngestionRunner against the recorded pages
|--------------------------------------------------------------------------
|
| ConnectorFactory and config/connectors.php are shared files (owned centrally) and do
| not know this platform yet, so the connector is constructed directly and
| injected through the same StubConnectorFactory the rest of the ingestion suite
| uses. Everything from IngestionRunner down is the real path: the page loop,
| the ON CONFLICT insert, the watermark promotion rule, the failure recording.
|
| The fixtures are synthesised from Trustpilot's documentation — see
| contracts/fixtures/platforms/trustpilot/README.md for what is documented and
| what is inferred.
|
*/

const TP_BU_ID = '5f3a1c9b2d4e6f8a0b1c2d3e';
const TP_KEY = 'tpkey-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789';
const TP_PAGE_SIZE = 3;

/** @return array{0: Company, 1: Integration} */
function trustpilotIntegration(array $attributes = []): array
{
    $company = Company::factory()->create();

    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => TP_BU_ID],
        'credentials' => ['api_key' => TP_KEY],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ], $attributes));

    return [$company, $integration];
}

function trustpilotWired(): TrustpilotConnector
{
    $connector = new TrustpilotConnector(
        businessUnitId: TP_BU_ID,
        apiKey: TP_KEY,
        baseUrl: 'https://api.trustpilot.com',
        limits: new ConnectorLimits(20, 3),
        timeout: 5,
        perPage: TP_PAGE_SIZE,
    );

    useConnector($connector);

    return $connector;
}

function tpBody(string $file): string
{
    return PlatformFixture::raw('trustpilot', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function tpReviews(string $file): array
{
    /** @var list<array<string, mixed>> $reviews */
    $reviews = PlatformFixture::json('trustpilot', $file)['reviews'];

    return $reviews;
}

/**
 * The external ids a page should produce: every review that carries a headline
 * or a body. One with neither is skipped rather than stored as an empty row.
 *
 * @return list<string>
 */
function tpIngestableIds(string ...$files): array
{
    $ids = [];

    foreach ($files as $file) {
        foreach (tpReviews($file) as $review) {
            $title = trim((string) ($review['title'] ?? ''));
            $text = trim((string) ($review['text'] ?? ''));

            if ($title !== '' || $text !== '') {
                $ids[] = (string) $review['id'];
            }
        }
    }

    sort($ids);

    return $ids;
}

/**
 * What the fake answers, as `page number => [body, status]`, plus an optional
 * `'*'` entry for every page.
 *
 * A mutable holder rather than a fresh `Http::fake()` per phase, because
 * **`Http::fake()` merges stub callbacks, it does not replace them**: a closure
 * registered first keeps answering, and a test that re-arms the fake for its
 * second run silently keeps getting the first run's pages. That is not
 * hypothetical — it is why "picks up a review published after the watermark"
 * failed on the first run of this file, and why the log test below looked green
 * while never exercising its happy path. One installed closure reading a
 * mutable script is the only form that lets a later phase answer differently.
 *
 * @param  array<int|string, array{0: string, 1: int}>|null  $script
 * @return array<int|string, array{0: string, 1: int}>
 */
function tpScript(?array $script = null): array
{
    static $current = [];

    if ($script !== null) {
        $current = $script;
    }

    return $current;
}

/**
 * @param  array<int|string, array{0: string, 1: int}>  $script
 */
function tpServe(array $script): void
{
    tpScript($script);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $script = tpScript();
        $page = (int) ($query['page'] ?? 1);
        $entry = $script[$page] ?? $script['*'] ?? [tpBody('page-empty.json'), 200];

        return Http::response($entry[0], $entry[1]);
    });
}

/**
 * The two recorded pages, served by the page number actually requested — so a
 * second run that legitimately restarts at page 1 gets page 1 again rather than
 * whatever a call counter happened to have reached.
 *
 * @return array<int, array{0: string, 1: int}>
 */
function tpFeedScript(): array
{
    return [
        1 => [tpBody('page-1.json'), 200],
        2 => [tpBody('page-2-last.json'), 200],
    ];
}

function tpServeFeed(): void
{
    tpServe(tpFeedScript());
}

/**
 * Capture what actually reaches the log, rendered, rather than trusting a spy's
 * arguments: a credential could arrive through the context array and only
 * become visible once Monolog formats it.
 */
function tpCaptureLog(Closure $run): string
{
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    $run();

    return collect($handler->getRecords())
        ->map(fn ($record) => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->implode("\n");
}

beforeEach(function () {
    RateLimiter::clear('connector:trustpilot');
    // Faked because the queue runs sync in tests: without it FeedbackIngested
    // reaches the analysis listener, which calls the real analyzer over HTTP.
    // That service is up in the dev stack and absent in CI (docs/LESSONS.md).
    Event::fake([FeedbackIngested::class]);
});

/*
|--------------------------------------------------------------------------
| A full run across both recorded pages
|--------------------------------------------------------------------------
*/

it('ingests every review of the feed and skips the one with no words in it', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(tpIngestableIds('page-1.json', 'page-2-last.json'))
        ->and($stored)->toHaveCount(4)
        // The fifth review carries an empty title and a null text; ingesting it
        // would put a blank row in the inbox and spend a unit of quota on it.
        ->and($stored)->not->toContain('60a1b2c3d4e5f60718293a05');

    Event::assertDispatchedTimes(FeedbackIngested::class, count($stored));
});

it('stores the mapped fields of a review under the right tenant', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    $review = collect(tpReviews('page-1.json'))->firstWhere('id', '60a1b2c3d4e5f60718293a01');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $review['id'])
        ->firstOrFail());

    expect($row->company_id)->toBe($company->id)
        ->and($row->integration_id)->toBe($integration->id)
        ->and($row->author)->toBe($review['consumer']['displayName'])
        // The documented decision: headline and body are one string, because
        // the analyzer reads feedbacks.body and nothing else.
        ->and($row->body)->toBe($review['title']."\n\n".$review['text'])
        ->and($row->source_url)->toBe('https://www.trustpilot.com/reviews/'.$review['id'])
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        // toIso8601String, not toDateTimeString: the latter drops the offset and
        // the column is timestamptz. Compared as an instant, not as a string.
        ->and($row->published_at?->equalTo(SyncCursor::parse($review['createdAt'])))->toBeTrue()
        ->and($row->raw_payload['id'])->toBe($review['id']);
});

it('carries the star rating of every ingested review through to the stored row', function () {
    // **There is no `feedbacks.rating` column.** The table is
    // company_id / integration_id / external_id / author / body / source_url /
    // published_at / raw_payload / analysis_status
    // (2026_09_02_000005_create_feedbacks_table.php) and IngestionRunner::persist()
    // writes exactly those, so `ConnectorItem::$rating` reaches the database only
    // inside `raw_payload`. That is the whole of what can be asserted at this
    // layer; the mapping of `stars` onto ConnectorItem::$rating is asserted
    // directly in tests/Unit/Connectors/TrustpilotConnectorTest.php.
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    $expected = collect(tpReviews('page-1.json'))
        ->merge(tpReviews('page-2-last.json'))
        ->filter(fn (array $review) => in_array(
            (string) $review['id'],
            tpIngestableIds('page-1.json', 'page-2-last.json'),
            true,
        ))
        ->mapWithKeys(fn (array $review) => [(string) $review['id'] => $review['stars']])
        ->all();

    $stored = asTenant($company, fn () => Feedback::query()
        ->get()
        ->mapWithKeys(fn (Feedback $row) => [$row->external_id => $row->raw_payload['stars']])
        ->all());

    ksort($stored);
    ksort($expected);

    expect($stored)->toBe($expected)
        ->and($expected)->toHaveCount(4);
});

it('reaches the end of the feed and promotes the watermark exactly once', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    $newest = collect(tpReviews('page-1.json'))
        ->sortByDesc(fn (array $review) => SyncCursor::parse($review['createdAt'])?->getTimestamp())
        ->first()['createdAt'];

    $cursor = SyncCursor::decode(asTenant(
        $company,
        fn () => Integration::query()->findOrFail($integration->id)->sync_cursor,
    ));

    // The run completed — two pages, neither empty, the second one short — so
    // the runner promotes. `page` rewinds to 1 because the feed is newest-first
    // and the next run has to start at the top to see what arrived since.
    expect($cursor->watermark)->toBe($newest)
        ->and($cursor->pending)->toBeNull()
        ->and($cursor->page)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — the second run is not a re-scan
|--------------------------------------------------------------------------
*/

it('stops the second run at the stored watermark instead of walking the feed again', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    tpServeFeed();
    Event::fake([FeedbackIngested::class]);

    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBe(4)
        // One request: page 1 comes back entirely at or below the watermark, so
        // the run stops there rather than paging to the end of the feed.
        ->and(Http::recorded()->count())->toBe(1);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['page'] === '1' && $query['orderBy'] === 'createdat.desc';
    });

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('picks up a review published after the watermark on the next run', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    // A newer review arrives at the top of the newest-first feed.
    $fresh = collect(tpReviews('page-1.json'))->firstWhere('id', '60a1b2c3d4e5f60718293a01');
    $fresh['id'] = '60a1b2c3d4e5f60718293a99';
    $fresh['createdAt'] = '2026-09-01T08:00:00Z';
    $fresh['title'] = 'Yeni bir yorum';

    tpServe([1 => [(string) json_encode(['reviews' => [$fresh]]), 200]]);
    Event::fake([FeedbackIngested::class]);

    runFetch($company, $integration->fresh());

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->sort()->values()->all());

    expect($stored)->toHaveCount(5)
        ->and($stored)->toContain('60a1b2c3d4e5f60718293a99');

    Event::assertDispatchedTimes(FeedbackIngested::class, 1);
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same review twice is one row
|--------------------------------------------------------------------------
*/

it('creates no duplicate row when the same page is served twice', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the feed genuinely serves the same reviews again and
    // UNIQUE (integration_id, external_id), not the watermark, is what stops
    // them.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    tpServeFeed();
    Event::fake([FeedbackIngested::class]);

    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBe(4);

    // Re-firing would re-analyse the review and burn a second unit of quota,
    // which is the whole reason I2 exists.
    Event::assertNotDispatched(FeedbackIngested::class);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential path
|--------------------------------------------------------------------------
*/

it('writes a sync_error that carries no credential material', function (int $status, string $file, string $expected) {
    // The upstream echoes the key back in its error body — the worst realistic
    // case, and the one a message built from a response would leak.
    tpServe(['*' => [(string) json_encode([
        'message' => 'UPSTREAM-ECHO-BODY',
        'apikey' => TP_KEY,
        'body' => tpBody($file),
    ]), $status]]);
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    try {
        runFetchDirect($company, $integration);
    } catch (Throwable) {
        // Transient failures are rethrown for the queue. The recorded state is
        // what this test is about.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)->toBe($expected)
        ->and($reloaded->sync_error)->not->toContain(TP_KEY)
        ->and($reloaded->sync_error)->not->toContain('UPSTREAM-ECHO-BODY')
        ->and($reloaded->status)->toBe('error');
})->with([
    'rejected key' => [401, 'error-unauthorized.json', 'The platform rejected the integration credentials.'],
    'unreadable business unit' => [403, 'error-forbidden.json', 'The platform rejected the integration credentials.'],
    'no such business unit' => [404, 'error-not-found.json', 'The integration settings are incomplete for this platform.'],
    'upstream down' => [500, 'error-unauthorized.json', 'The platform could not be reached.'],
]);

it('logs nothing that contains the api key, on the failure path or the happy one', function () {
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    $failing = tpCaptureLog(function () use ($company, $integration) {
        // The upstream echoes the key back at us in its error body.
        tpServe(['*' => [(string) json_encode(['message' => TP_KEY]), 401]]);

        try {
            runFetchDirect($company, $integration);
        } catch (Throwable) {
        }
    });

    $happy = tpCaptureLog(function () use ($company, $integration) {
        tpServeFeed();
        runFetch($company, $integration->fresh());
    });

    foreach ([$failing, $happy] as $written) {
        expect($written)->not->toContain(TP_KEY)
            ->and($written)->not->toContain('api_key')
            ->and($written)->not->toContain('apikey')
            ->and($written)->not->toContain('"credentials"');
    }

    // The failure path has to have written *something*, and the happy path has
    // to have actually been happy — otherwise both halves prove nothing.
    expect($failing)->not->toBe('')
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(4);
});

it('never sends the api key anywhere but the apikey header', function () {
    tpServeFeed();
    trustpilotWired();
    [$company, $integration] = trustpilotIntegration();

    runFetch($company, $integration);

    Http::assertSent(function ($request) {
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            $headers[strtolower((string) $name)] = $values;
        }

        return ($headers['apikey'] ?? null) === [TP_KEY]
            && ! str_contains($request->url(), TP_KEY)
            && $request->body() === '';
    });
});

it('keeps the credential out of everything that leaves the database', function () {
    [$company, $integration] = trustpilotIntegration();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect(json_encode($reloaded))->not->toContain(TP_KEY)
        ->and(json_encode($reloaded->toArray()))->not->toContain(TP_KEY)
        // The credential is still there — hidden, not lost.
        ->and($reloaded->credentials['api_key'])->toBe(TP_KEY);
});
