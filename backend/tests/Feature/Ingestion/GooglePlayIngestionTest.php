<?php

use App\Events\FeedbackIngested;
use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\GooglePlayAccessToken;
use App\Support\Connectors\GooglePlayConnector;
use App\Support\Connectors\PlatformConnector;
use App\Support\Connectors\SyncCursor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| Google Play, end to end through IngestionRunner
|--------------------------------------------------------------------------
|
| ConnectorFactory and config/connectors.php are shared files (owned centrally), so at
| the time this file was written the factory had no googleplay arm and could not
| build this connector at all (docs/contracts/w8-connectors.md section 1). The
| factory is therefore stood in for below with one that builds the connector
| from the same integration columns the real arm will read — settings.package_name
| and credentials.{client_email,private_key} — so that everything downstream of
| the factory, which is the whole ingestion path, is exercised for real.
|
| The factory-level test belongs centrally, once the arm exists.
|
| The service-account key is generated in-process. No key material is committed,
| and the fixtures under tests/Fixtures/platforms/googleplay/ are synthesised
| from published documentation — see the provenance README beside them.
|
*/

const GPI_PACKAGE = 'com.example.omnihear';
const GPI_CLIENT_EMAIL = 'omnihear-fixture@example-project.iam.gserviceaccount.invalid';
const GPI_BASE_URL = 'https://androidpublisher.googleapis.com';
const GPI_TOKEN_URL = 'https://oauth2.googleapis.com/token';

/**
 * @return array{private: string, public: string}
 */
function gpiKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($resource === false) {
            throw new RuntimeException('This environment cannot generate an RSA key pair.');
        }

        openssl_pkey_export($resource, $exported);

        $keys = [
            'private' => (string) $exported,
            'public' => (string) (openssl_pkey_get_details($resource)['key'] ?? ''),
        ];
    }

    return $keys;
}

/**
 * Stands in for the ConnectorFactory arm owned centrally.
 */
function useGooglePlayConnector(int $maxPages = 10, int $maxResults = 100): void
{
    app()->instance(ConnectorFactory::class, new class($maxPages, $maxResults) extends ConnectorFactory
    {
        public function __construct(private int $maxPages, private int $maxResults) {}

        public function for(Integration $integration): PlatformConnector
        {
            $settings = is_array($integration->settings) ? $integration->settings : [];
            $credentials = is_array($integration->credentials) ? $integration->credentials : [];

            return new GooglePlayConnector(
                packageName: (string) ($settings['package_name'] ?? ''),
                token: new GooglePlayAccessToken(
                    clientEmail: (string) ($credentials['client_email'] ?? ''),
                    privateKey: (string) ($credentials['private_key'] ?? ''),
                    integrationId: (int) $integration->id,
                    tokenUrl: GPI_TOKEN_URL,
                    timeout: 5,
                ),
                baseUrl: GPI_BASE_URL,
                limits: new ConnectorLimits($this->maxPages, 3),
                timeout: 5,
                maxResults: $this->maxResults,
            );
        }
    });
}

/**
 * @return array{0: Company, 1: Integration}
 */
function googlePlayIntegration(array $attributes = [], ?Company $company = null): array
{
    $company ??= Company::factory()->create();

    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'googleplay',
        'settings' => ['package_name' => GPI_PACKAGE],
        'credentials' => [
            'client_email' => GPI_CLIENT_EMAIL,
            'private_key' => gpiKeys()['private'],
        ],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ], $attributes));

    return [$company, $integration];
}

function gpiRaw(string $file): string
{
    return PlatformFixture::raw('googleplay', $file);
}

/**
 * @return array<string, mixed>
 */
function gpiJson(string $file): array
{
    return PlatformFixture::json('googleplay', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function gpiReviews(string $file): array
{
    /** @var list<array<string, mixed>> $reviews */
    $reviews = gpiJson($file)['reviews'];

    return $reviews;
}

/**
 * The external ids a set of pages should produce: every review carrying a user
 * comment with text.
 *
 * @return list<string>
 */
function gpiIngestableIds(string ...$files): array
{
    $ids = [];

    foreach ($files as $file) {
        foreach (gpiReviews($file) as $review) {
            foreach ($review['comments'] ?? [] as $comment) {
                if (trim((string) ($comment['userComment']['text'] ?? '')) !== '') {
                    $ids[] = (string) $review['reviewId'];

                    break;
                }
            }
        }
    }

    sort($ids);

    return $ids;
}

/**
 * Installs the one and only Http::fake() closure of a test, and returns the
 * handle that reprograms it.
 *
 * **Http::fake() merges stub callbacks, it does not replace them.** Calling it a
 * second time inside a test leaves the first closure in charge, so the phase the
 * second call was meant to set up never runs — and the test stays green while
 * proving something else entirely. One closure, reprogrammed through
 * `$script->handler`, is the only shape that cannot go wrong; `reviewRequests`
 * is counted here rather than read from Http::recorded() so a phase boundary is
 * something the test can see.
 *
 * The token exchange is answered unconditionally: it is infrastructure, not the
 * subject of any test in this file.
 */
function gpiScript(): stdClass
{
    $script = new stdClass;
    $script->handler = fn () => Http::response('{"reviews":[]}', 200);
    $script->reviewRequests = 0;

    Http::fake(function ($request) use ($script) {
        if (str_contains($request->url(), 'oauth2.googleapis.com')) {
            return Http::response(gpiRaw('token-response.json'), 200);
        }

        $script->reviewRequests++;

        return ($script->handler)($request);
    });

    return $script;
}

/**
 * Answer the listing with these fixture pages in order, repeating the last one.
 */
function gpiPages(stdClass $script, string ...$files): stdClass
{
    $served = 0;

    $script->handler = function () use (&$served, $files) {
        $file = $files[min($served, count($files) - 1)];
        $served++;

        return Http::response(gpiRaw($file), 200);
    };

    return $script;
}

/**
 * The common case: one phase, one page sequence.
 */
function gpiServe(string ...$files): stdClass
{
    return gpiPages(gpiScript(), ...$files);
}

/**
 * Capture what actually reaches the log, rendered, rather than trusting a spy:
 * a credential could arrive through the context array and only become visible
 * once Monolog formats it.
 */
function gpiCaptureLog(Closure $run): string
{
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    $run();

    return collect($handler->getRecords())
        ->map(fn ($record) => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->implode("\n");
}

beforeEach(function () {
    RateLimiter::clear('connector:googleplay');
    Event::fake([FeedbackIngested::class]);
    useGooglePlayConnector();
});

/*
|--------------------------------------------------------------------------
| A full run across two pages
|--------------------------------------------------------------------------
*/

it('ingests every review that carries a user comment and skips the rest', function () {
    gpiServe('page-1.json', 'page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(gpiIngestableIds('page-1.json', 'page-2-end.json'))
        ->and($stored)->not->toBeEmpty();

    Event::assertDispatchedTimes(FeedbackIngested::class, count($stored));
});

it('stores the mapped fields of a review against the owning tenant', function () {
    gpiServe('page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $review = gpiReviews('page-2-end.json')[0];
    $comment = $review['comments'][0]['userComment'];
    $seconds = (int) $comment['lastModified']['seconds'];

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $review['reviewId'])
        ->firstOrFail());

    expect($row->company_id)->toBe($company->id)
        ->and($row->integration_id)->toBe($integration->id)
        ->and($row->author)->toBe($review['authorName'])
        ->and($row->body)->toBe($comment['text'])
        // The star rating does NOT land in a column of its own, and that is not
        // this connector's doing: `feedbacks` has no rating column
        // (2026_09_02_000005_create_feedbacks_table.php) and IngestionRunner
        // does not persist ConnectorItem::$rating for any platform. The value
        // is mapped correctly — GooglePlayConnectorTest asserts that on the
        // item — and then survives only inside raw_payload. Asserted both ways
        // here so the gap stays visible instead of silently absent.
        ->and($row->rating)->toBeNull()
        ->and($row->raw_payload['comments'][0]['userComment']['starRating'])->toBe($comment['starRating'])
        ->and($row->source_url)->toBe('https://play.google.com/store/apps/details?id='.GPI_PACKAGE)
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        // toIso8601String all the way down: published_at is timestamptz, and a
        // value that lost its offset would be the right wall clock in the wrong
        // zone (docs/LESSONS.md, 2026-09-02).
        ->and($row->published_at?->equalTo(CarbonImmutable::createFromTimestampUTC($seconds)))->toBeTrue()
        ->and($row->raw_payload['reviewId'])->toBe($review['reviewId'])
        // The developer reply is kept in raw_payload, because that column is
        // what the provider sent — but it is not what was analysed.
        ->and($row->body)->not->toContain('DEVELOPER-REPLY-MUST-NOT-BE-INGESTED');
});

it('masks an email address out of a review body on the way in', function () {
    // Spec 8: feedback bodies are personal data, and one fixture review carries
    // an address on purpose so the masking hook is on this path too.
    gpiServe('page-1.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $withAddress = collect(gpiReviews('page-1.json'))
        ->first(fn (array $r) => str_contains((string) $r['comments'][0]['userComment']['text'], '@'));

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $withAddress['reviewId'])
        ->firstOrFail());

    expect($withAddress)->not->toBeNull()
        ->and($row->body)->toContain('[email]')
        ->and($row->body)->not->toContain('destek.musteri@example.invalid');
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — the second run stops at the watermark
|--------------------------------------------------------------------------
*/

it('promotes the watermark on a complete run and stops there on the next one', function () {
    $script = gpiServe('page-1.json', 'page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $newest = collect(gpiReviews('page-1.json'))
        ->map(fn (array $r) => (int) $r['comments'][0]['userComment']['lastModified']['seconds'])
        ->max();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));
    $cursor = SyncCursor::decode($reloaded->sync_cursor);

    expect(SyncCursor::parse($cursor->watermark)?->getTimestamp())->toBe($newest)
        ->and($cursor->pending)->toBeNull()
        // A page token is a within-run value; carrying one into the next run
        // would replay a position instead of re-listing the seven-day window.
        ->and($cursor->token)->toBeNull()
        ->and(strlen((string) $reloaded->sync_cursor))->toBeLessThan(255);

    // Run two: the top of the listing again, now entirely at or below the
    // watermark. Reprogrammed rather than re-faked — a second Http::fake() would
    // be merged behind the first closure and this phase would never run.
    $script->reviewRequests = 0;
    gpiPages($script, 'page-1.json', 'page-2-end.json');
    Event::fake([FeedbackIngested::class]);
    RateLimiter::clear('connector:googleplay');

    $before = asTenant($company, fn () => Feedback::query()->count());
    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($before)
        ->and($before)->toBeGreaterThan(0)
        // One request, and it was page one: the run stopped at the stored
        // position on the very first page rather than walking the window to its
        // end, which is what spec 6.1 asks for.
        ->and($script->reviewRequests)->toBe(1);

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('keeps going past an empty page instead of treating it as the end', function () {
    gpiServe('page-empty-continues.json', 'page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    // Set comparison, not sequence: gpiIngestableIds() returns its ids sorted,
    // so this side is sorted to match. The query carries no orderBy and
    // PostgreSQL is free to return the rows in any order, which made this
    // assertion pass alone and fail inside the full suite. Do not remove the
    // sort; what is under test is which rows exist, not the order they arrived.
    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(gpiIngestableIds('page-2-end.json'));

    // The run saw an empty page, so the runner must not promote: everything
    // that page might have held is older than the new high-water mark and would
    // be buried under it forever (docs/LESSONS.md, 2026-09-02).
    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect(SyncCursor::decode($reloaded->sync_cursor)->watermark)->toBeNull()
        ->and(SyncCursor::decode($reloaded->sync_cursor)->pending)->not->toBeNull();
});

it('stops at the runners page ceiling and does not promote a position it never reached', function () {
    useGooglePlayConnector(maxPages: 3);

    $script = gpiScript();
    $script->handler = function () use ($script) {
        $n = $script->reviewRequests;

        return Http::response((string) json_encode([
            'reviews' => [[
                'reviewId' => 'gp:FIXTURE-endless-'.$n,
                'authorName' => 'reviewer-'.$n,
                'comments' => [[
                    'userComment' => [
                        'text' => 'endless page '.$n,
                        'lastModified' => ['seconds' => (string) (1788340500 - ($n * 3600))],
                        'starRating' => 4,
                    ],
                ]],
            ]],
            'tokenPagination' => ['nextPageToken' => 'gp-fixture-token-'.$n],
        ]), 200);
    };

    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect($script->reviewRequests)->toBe(3)
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(3);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));
    $cursor = SyncCursor::decode($reloaded->sync_cursor);

    // hasMore was still true when the runner cut the run short, so the watermark
    // stays where it was and the next run walks the same ground again —
    // invariant I2 turns the re-fetch into no new rows.
    expect($cursor->watermark)->toBeNull()
        ->and($cursor->token)->toBe('gp-fixture-token-3')
        ->and(strlen((string) $reloaded->sync_cursor))->toBeLessThan(255);
});

/*
|--------------------------------------------------------------------------
| An application with nothing in the seven-day window
|--------------------------------------------------------------------------
*/

it('syncs cleanly, run after run, when the window holds no reviews at all', function () {
    // `{}` is what the endpoint answers for an app with nothing in the window,
    // and that is the steady state for a quiet app — not an error, and not
    // something that may accumulate into one.
    //
    // The interaction to check is ConnectorLimits::$maxConsecutiveEmptyPages.
    // IngestionRunner counts the empty streak in a local that is reset at the
    // top of every run, so three quiet runs are three streaks of one, never one
    // streak of three. Asserted here rather than reasoned about, because the
    // failure would be an integration that switches itself to `error` after a
    // quiet week.
    $script = gpiServe('page-empty-window.json');
    [$company, $integration] = googlePlayIntegration();

    $runs = (int) config('connectors.platforms.fixture.max_consecutive_empty_pages', 3) + 1;

    for ($run = 0; $run < $runs; $run++) {
        RateLimiter::clear('connector:googleplay');
        FetchFeedbackJob::dispatchSync($company->id, $integration->id);

        $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

        expect($reloaded->status)->toBe('active')
            ->and($reloaded->sync_error)->toBeNull()
            ->and($reloaded->last_synced_at)->not->toBeNull()
            ->and(SyncCursor::decode($reloaded->sync_cursor)->watermark)->toBeNull();
    }

    expect($script->reviewRequests)->toBe($runs)
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(0);

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('picks up the first review to arrive after a run of empty windows', function () {
    // The other half: an empty window must not poison the position, so the very
    // next run that finds something ingests it normally.
    $script = gpiServe('page-empty-window.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe(0);

    $script->reviewRequests = 0;
    gpiPages($script, 'page-2-end.json');
    RateLimiter::clear('connector:googleplay');

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(gpiIngestableIds('page-2-end.json'))
        ->and($script->reviewRequests)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same review twice is one row
|--------------------------------------------------------------------------
*/

it('creates no duplicate row when the same listing page is served twice', function () {
    $script = gpiServe('page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the listing genuinely serves the same reviews again
    // and the unique index, not the watermark, is what stops them. That is the
    // seven-day window in miniature: re-listing is the normal case here.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    Event::fake([FeedbackIngested::class]);
    RateLimiter::clear('connector:googleplay');
    $script->reviewRequests = 0;
    gpiPages($script, 'page-2-end.json');

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBeGreaterThan(0)
        // The second phase really ran: without this the whole test would pass
        // on a listing that was never served twice.
        ->and($script->reviewRequests)->toBe(1);

    // Re-firing would re-analyse the review and burn a second unit of quota,
    // which is the whole reason I2 exists.
    Event::assertNotDispatched(FeedbackIngested::class);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the service-account key never reaches anything persisted
|--------------------------------------------------------------------------
*/

it('writes a sync_error that carries no credential material', function (int $status, string $file, string $expected) {
    // The upstream echoes the credentials back in its error body — the worst
    // realistic case, and the one a message built from a response would leak.
    $script = gpiScript();
    $script->handler = fn () => Http::response((string) json_encode([
        'error' => 'UPSTREAM-ECHO-BODY',
        'client_email' => GPI_CLIENT_EMAIL,
        'private_key' => gpiKeys()['private'],
        'body' => gpiRaw($file),
    ]), $status);

    [$company, $integration] = googlePlayIntegration();

    try {
        (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
    } catch (Throwable) {
        // Transient failures are rethrown for the queue. The recorded state is
        // what this test is about.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)->toBe($expected)
        ->and($reloaded->sync_error)->not->toContain(GPI_CLIENT_EMAIL)
        ->and($reloaded->sync_error)->not->toContain('PRIVATE KEY')
        ->and($reloaded->sync_error)->not->toContain(gpiKeys()['private'])
        ->and($reloaded->sync_error)->not->toContain('UPSTREAM-ECHO-BODY')
        ->and($reloaded->status)->toBe('error');
})->with([
    'rejected token' => [401, 'error-unauthorized.json', 'The platform rejected the integration credentials.'],
    'no access to the app' => [403, 'error-forbidden.json', 'The platform rejected the integration credentials.'],
    'unknown package' => [404, 'error-not-found.json', 'The integration settings are incomplete for this platform.'],
    'upstream down' => [500, 'error-unauthorized.json', 'The platform could not be reached.'],
]);

it('records a private key it cannot sign with as a configuration problem, not a platform one', function () {
    gpiServe('page-1.json');
    [$company, $integration] = googlePlayIntegration([
        'credentials' => ['client_email' => GPI_CLIENT_EMAIL, 'private_key' => 'not-a-key'],
    ]);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe('The integration settings are incomplete for this platform.')
        ->and($reloaded->sync_error)->not->toContain('PEM')
        ->and($reloaded->sync_error)->not->toContain('error:');

    Http::assertNothingSent();
});

it('logs nothing that contains the credential, on the failure path or the happy one', function () {
    [$company, $integration] = googlePlayIntegration();

    // One closure for both phases, reprogrammed between them. A second
    // Http::fake() would be merged behind the first and the capped phase below
    // would silently replay the 401 instead — leaving the runner's other log
    // line completely uncovered while the test still passed.
    $script = gpiScript();

    $failing = gpiCaptureLog(function () use ($company, $integration, $script) {
        $script->handler = fn () => Http::response((string) json_encode([
            'client_email' => GPI_CLIENT_EMAIL,
            'private_key' => gpiKeys()['private'],
        ]), 401);

        try {
            (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
        } catch (Throwable) {
        }
    });

    $capped = gpiCaptureLog(function () use ($company, $integration, $script) {
        // The other line the ingestion path writes: the runner's capped-run
        // warning. It has to be provoked, or this test covers only one of them.
        useGooglePlayConnector(maxPages: 2);
        RateLimiter::clear('connector:googleplay');

        $script->reviewRequests = 0;
        $script->handler = fn () => Http::response((string) json_encode([
            'reviews' => [],
            'tokenPagination' => ['nextPageToken' => 'gp-fixture-token-'.$script->reviewRequests],
        ]), 200);

        FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    });

    // Both phases genuinely ran, and the second one really did hit the ceiling.
    expect($script->reviewRequests)->toBe(2)
        ->and($capped)->toContain('Connector run capped before the stream ended.')
        ->and($failing)->toContain('Integration sync failed.');

    foreach ([$failing, $capped] as $written) {
        expect($written)->not->toBe('')
            ->and($written)->not->toContain(GPI_CLIENT_EMAIL)
            ->and($written)->not->toContain('PRIVATE KEY')
            ->and($written)->not->toContain(gpiKeys()['private'])
            ->and($written)->not->toContain('private_key')
            ->and($written)->not->toContain('"credentials"')
            ->and($written)->not->toContain('Authorization')
            ->and($written)->not->toContain('Bearer ');
    }
});

it('serializes the service account into nothing that leaves the database', function () {
    [$company, $integration] = googlePlayIntegration();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    // The queued job payload is the one place a credential could travel without
    // anyone looking: it is written to Redis and to failed_jobs.
    $job = serialize(new FetchFeedbackJob($company->id, $integration->id));

    expect(json_encode($reloaded))->not->toContain(gpiKeys()['private'])
        ->and(json_encode($reloaded->toArray()))->not->toContain(gpiKeys()['private'])
        ->and($job)->not->toContain(gpiKeys()['private'])
        ->and($job)->not->toContain(GPI_CLIENT_EMAIL)
        // The credential is still there — hidden, not lost.
        ->and($reloaded->credentials['private_key'])->toBe(gpiKeys()['private']);
});

it('never sends the service account anywhere but the signed assertion', function () {
    gpiServe('page-2-end.json');
    [$company, $integration] = googlePlayIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), GPI_CLIENT_EMAIL)
        && ! str_contains($request->url(), 'PRIVATE KEY')
        && ! str_contains($request->body(), gpiKeys()['private'])
        && ! str_contains($request->body(), GPI_CLIENT_EMAIL));
});

/*
|--------------------------------------------------------------------------
| Invariant I1 — another tenant sees none of it
|--------------------------------------------------------------------------
*/

it('never lets another tenant see the rows behind a google play integration', function () {
    gpiServe('page-2-end.json');

    [$owner, $integration] = googlePlayIntegration();
    FetchFeedbackJob::dispatchSync($owner->id, $integration->id);

    $ingested = asTenant($owner, fn () => Feedback::query()->count());
    [, $outsider] = tenant();

    $response = $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/feedbacks');

    expect($ingested)->toBeGreaterThan(0)
        ->and($response->json('data'))->toBe([]);

    expect($response->getContent())->not->toContain(gpiKeys()['private']);
});
