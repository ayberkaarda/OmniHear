<?php

use App\Events\FeedbackIngested;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\DnsResolver;
use App\Support\Connectors\MastodonConnector;
use App\Support\Connectors\OutboundHostPolicy;
use App\Support\Connectors\SyncCursor;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| Mastodon, end to end — IngestionRunner against the recorded pages
|--------------------------------------------------------------------------
|
| ConnectorFactory and config/connectors.php are shared files (owned centrally) and do
| not know this platform yet, so the connector is constructed directly and
| injected through the same StubConnectorFactory the rest of the ingestion suite
| uses. Everything from IngestionRunner down is the real path: the page loop,
| the ON CONFLICT insert, the PII masking, the failure recording.
|
| The fixtures were recorded live from mastodon.social and then redacted — see
| contracts/fixtures/platforms/social/README.md for what is recorded and what is
| inferred.
|
| **The two pages read forward.** `min_id` returns the statuses *immediately
| newer* than the token, so `page-2-last.json` is the newer page and the run
| walks page-1 -> page-2, not the other way round.
|
*/

const MD_HOST = 'https://social.example.invalid';
const MD_TAG = 'omnihear';
const MD_PAGE_SIZE = 5;

/** @return array{0: Company, 1: Integration} */
function mastodonIntegration(array $attributes = []): array
{
    $company = Company::factory()->create();

    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'social',
        'settings' => ['instance_url' => MD_HOST, 'hashtag' => MD_TAG],
        // No credential at all: the public hashtag timeline takes none. This is
        // the only connector besides App Store in that position.
        'credentials' => [],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ], $attributes));

    return [$company, $integration];
}

function mastodonWired(int $maxPages = 20): MastodonConnector
{
    $connector = new MastodonConnector(
        instanceUrl: MD_HOST,
        hashtag: MD_TAG,
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        limit: MD_PAGE_SIZE,
    );

    useConnector($connector);

    return $connector;
}

function mdBody(string $file): string
{
    return PlatformFixture::raw('social', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function mdStatuses(string $file): array
{
    /** @var list<array<string, mixed>> $statuses */
    $statuses = json_decode(mdBody($file), true, 64, JSON_THROW_ON_ERROR);

    return $statuses;
}

/**
 * The external ids a set of pages should produce: every status that is neither
 * a boost nor empty once its markup is stripped.
 *
 * @return list<string>
 */
function mdIngestableIds(string ...$files): array
{
    $ids = [];

    foreach ($files as $file) {
        foreach (mdStatuses($file) as $status) {
            if ($status['reblog'] !== null) {
                continue;
            }

            if (trim(strip_tags((string) $status['content'])) === '') {
                continue;
            }

            $ids[] = (string) $status['id'];
        }
    }

    sort($ids);

    return $ids;
}

/**
 * What the fake answers, keyed by the `min_id` the request carried — `'cold'`
 * for a request that carried none, plus an optional `'*'` for every request.
 *
 * A mutable holder read by **one** installed closure, rather than a second
 * `Http::fake()` per phase: `Http::fake()` *merges* stub callbacks, it does not
 * replace them, so a closure registered first keeps answering and a test that
 * re-arms the fake for its second run silently keeps getting the first run's
 * pages. It cost two W8 workstreams a debugging pass each.
 *
 * Keyed by the request rather than by a call counter for the same reason: a
 * second run legitimately starts wherever its stored token points, and a
 * counter would hand it whichever page happened to be next in the script.
 *
 * @param  array<string, array{0: string, 1: int}>|null  $script
 * @return array<string, array{0: string, 1: int}>
 */
function mdScript(?array $script = null): array
{
    static $current = [];

    if ($script !== null) {
        $current = $script;
    }

    return $current;
}

/**
 * @param  array<string, array{0: string, 1: int}>  $script
 */
function mdServe(array $script): void
{
    mdScript($script);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $script = mdScript();
        $key = isset($query['min_id']) ? (string) $query['min_id'] : 'cold';
        $entry = $script[$key] ?? $script['*'] ?? [mdBody('page-empty.json'), 200];

        return Http::response($entry[0], $entry[1]);
    });
}

/**
 * The two recorded pages as one forward stream: a cold start reads page-1, and
 * a run whose token is page-1's newest id reads page-2 behind it.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function mdFeedScript(): array
{
    return [
        'cold' => [mdBody('page-1.json'), 200],
        mdNewestId('page-1.json') => [mdBody('page-2-last.json'), 200],
        mdNewestId('page-2-last.json') => [mdBody('page-empty.json'), 200],
    ];
}

function mdNewestId(string $file): string
{
    return (string) mdStatuses($file)[0]['id'];
}

function mdServeFeed(): void
{
    mdServe(mdFeedScript());
}

function mdStoredCursor(Company $company, Integration $integration): SyncCursor
{
    return SyncCursor::decode(asTenant(
        $company,
        fn () => Integration::query()->findOrFail($integration->id)->sync_cursor,
    ));
}

/**
 * Capture what actually reaches the log, rendered, rather than trusting a spy's
 * arguments.
 */
function mdCaptureLog(Closure $run): string
{
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    $run();

    return collect($handler->getRecords())
        ->map(fn ($record) => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->implode("\n");
}

beforeEach(function () {
    RateLimiter::clear('connector:social');
    // Faked because the queue runs sync in tests: without it FeedbackIngested
    // reaches the analysis listener, which calls the real analyzer over HTTP.
    Event::fake([FeedbackIngested::class]);
    // OutboundHostPolicy resolves the instance host before the fetch, and the
    // `.invalid` host these fixtures use never resolves on a real resolver; a
    // permissive fake keeps the SSRF gate on its allow path.
    OutboundHostPolicy::resolveUsing(new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
});

afterEach(fn () => OutboundHostPolicy::resolveUsing(null));

/*
|--------------------------------------------------------------------------
| The first run reads the newest page and stops there
|--------------------------------------------------------------------------
*/

it('ingests the newest page on a first sync and asks for nothing behind it', function () {
    // A first sync that walked a busy hashtag back through its history would
    // spend the tenant's whole analysis quota before showing them anything. The
    // page size is the bound, so the cold start is exactly one request.
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(mdIngestableIds('page-1.json'))
        ->and($stored)->toHaveCount(MD_PAGE_SIZE)
        ->and(Http::recorded()->count())->toBe(1);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ! array_key_exists('min_id', $query)
            && $query['limit'] === (string) MD_PAGE_SIZE;
    });

    Event::assertDispatchedTimes(FeedbackIngested::class, MD_PAGE_SIZE);
});

it('stores the mapped fields of a status under the right tenant', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $status = mdStatuses('page-1.json')[0];

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $status['id'])
        ->firstOrFail());

    expect($row->company_id)->toBe($company->id)
        ->and($row->integration_id)->toBe($integration->id)
        ->and($row->author)->toBe($status['account']['display_name'])
        ->and($row->source_url)->toBe($status['url'])
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        // The markup is gone and the text the poster wrote is what the analyzer
        // will read.
        ->and($row->body)->not->toContain('<')
        ->and($row->body)->toContain('#'.MD_TAG)
        // toIso8601String, not toDateTimeString: the latter drops the offset
        // and the column is timestamptz, which once put a row seven hours off.
        // Compared as an *instant* rather than as a string, and to the second:
        // the recorded created_at carries milliseconds and toIso8601String()
        // renders whole seconds, so the stored value is truncated, never
        // shifted. A sub-second truncation cannot reorder an inbox; an hour
        // offset can, and that is what this asserts is absent.
        ->and($row->published_at?->getTimestamp())
        ->toBe(SyncCursor::parse($status['created_at'])?->getTimestamp())
        ->and($row->published_at?->getOffset())->toBe(0)
        ->and($row->raw_payload['id'])->toBe($status['id']);
});

it('stores no rating for any status', function () {
    // **There is no `feedbacks.rating` column** — the table is
    // company_id / integration_id / external_id / author / body / source_url /
    // published_at / raw_payload / analysis_status — so ConnectorItem::$rating
    // reaches the database only inside raw_payload, and this connector never
    // sets it: favourites and boosts are popularity, not sentiment. That the
    // item's rating is null is asserted directly in
    // tests/Unit/Connectors/MastodonConnectorTest.php.
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $stored = asTenant($company, fn () => Feedback::query()->get());

    expect($stored)->not->toBeEmpty();

    foreach ($stored as $row) {
        expect(array_key_exists('rating', $row->raw_payload))->toBeFalse();
    }
});

it('stores the position it reached and nothing else in the cursor', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $cursor = mdStoredCursor($company, $integration);

    // The token is the whole position — the Zendesk shape. No watermark is
    // encoded next to it, because the timeline speaks ids and a watermark on
    // created_at would be a second, weaker encoding of the same thing.
    expect($cursor->token)->toBe(mdNewestId('page-1.json'))
        ->and($cursor->watermark)->toBeNull()
        ->and($cursor->pending)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The second run walks forward from the token
|--------------------------------------------------------------------------
*/

it('asks only for what is newer than the stored token on the next run', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    Event::fake([FeedbackIngested::class]);
    runFetch($company, $integration->fresh());

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($afterFirst)->toBe(MD_PAGE_SIZE)
        ->and($stored)->toBe(mdIngestableIds('page-1.json', 'page-2-last.json'))
        // page-2-last carries three statuses: a boost, a body that strips to
        // nothing, and one real post.
        ->and($stored)->toHaveCount(MD_PAGE_SIZE + 1)
        // Two requests in total: the cold start, then the second run's single
        // short page. The second phase really was reached.
        ->and(Http::recorded()->count())->toBe(2);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['min_id'] ?? null) === mdNewestId('page-1.json');
    });

    Event::assertDispatchedTimes(FeedbackIngested::class, 1);

    expect(mdStoredCursor($company, $integration)->token)->toBe(mdNewestId('page-2-last.json'));
});

it('skips a boost and a status with no words in it, at the database layer', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);
    runFetch($company, $integration->fresh());

    $boost = collect(mdStatuses('page-2-last.json'))->first(fn (array $s) => $s['reblog'] !== null);
    $empty = collect(mdStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['reblog'] === null && trim(strip_tags((string) $s['content'])) === '');

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());

    expect($stored)->not->toContain($boost['id'])
        // Not under the nested original's id either: it will arrive on its own
        // if it carries the hashtag.
        ->and($stored)->not->toContain($boost['reblog']['id'])
        ->and($stored)->not->toContain($empty['id'])
        // Nothing empty ever reaches the inbox; a blank row would sit there and
        // spend a unit of analysis quota on nothing.
        ->and(asTenant($company, fn () => Feedback::query()->where('body', '')->count()))->toBe(0);
});

it('keeps the token where it is when a run finds nothing new', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);
    runFetch($company, $integration->fresh());
    $afterSecond = asTenant($company, fn () => Feedback::query()->count());
    $token = mdStoredCursor($company, $integration)->token;

    Event::fake([FeedbackIngested::class]);
    // The third run's min_id is page-2's newest id, which the script answers
    // with the recorded empty page.
    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterSecond)
        // Dropping the token here would restart the next run from the newest
        // page and re-ingest everything. I2 would absorb the rows; the analysis
        // quota would not absorb the work.
        ->and(mdStoredCursor($company, $integration)->token)->toBe($token)
        ->and(Http::recorded()->count())->toBe(3);

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('resumes a run the runners page cap cut short instead of persisting a position it never reached', function () {
    // The cap is a runaway-loop ceiling, never the connector's end condition. A
    // connector that reported hasMore=false on hitting it would tell the runner
    // the run completed (docs/LESSONS.md, capped-run entry).
    // Every request answers a full page, so the run would keep walking forward
    // until something stopped it.
    mdServe(['*' => [mdBody('page-1.json'), 200]]);
    mastodonWired(maxPages: 1);
    [$company, $integration] = mastodonIntegration([
        // Not a cold start: the cold start stops after one page by design, so
        // only a later run can reach the cap at all.
        'sync_cursor' => (string) json_encode(['page' => 1, 'token' => '117800000000000001']),
    ]);

    $captured = mdCaptureLog(fn () => runFetch($company, $integration));

    expect(Http::recorded()->count())->toBe(1)
        // The connector still says there is more, so the runner records that the
        // run was cut short rather than treating it as complete.
        ->and($captured)->toContain('Connector run capped before the stream ended.')
        // And the stored token is the page it actually read, so the next run
        // picks up exactly there.
        ->and(mdStoredCursor($company, $integration)->token)->toBe(mdNewestId('page-1.json'));
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same status twice is one row
|--------------------------------------------------------------------------
*/

it('creates no duplicate row when the same page is served twice', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the feed genuinely serves the same statuses again and
    // UNIQUE (integration_id, external_id), not the token, is what stops them.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    Event::fake([FeedbackIngested::class]);
    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBe(MD_PAGE_SIZE);

    // Re-firing would re-analyse the post and burn a second unit of quota,
    // which is the whole reason I2 exists.
    Event::assertNotDispatched(FeedbackIngested::class);
});

/*
|--------------------------------------------------------------------------
| Spec §8 — the author is a name, not an address
|--------------------------------------------------------------------------
*/

it('never lets a remote handle become the masked address in the author column', function () {
    // For a remote account `acct` is `user@domain`, and IngestionRunner::maskPii
    // rewrites anything address-shaped to `[email]`. Passing acct through as the
    // author would put that literal string in the inbox where a person's name
    // belongs — which is why the connector reads display_name and never acct.
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $remote = collect(mdStatuses('page-1.json'))
        ->first(fn (array $s) => str_contains((string) $s['account']['acct'], '@')
            && $s['account']['display_name'] !== '');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $remote['id'])
        ->firstOrFail());

    expect($remote['account']['acct'])->toContain('@')
        ->and($row->author)->toBe($remote['account']['display_name'])
        ->and($row->author)->not->toContain('[email]')
        ->and($row->author)->not->toContain('@')
        // The masking of acct *inside* raw_payload is correct and stays: it is a
        // direct identifier the product has no use for.
        ->and($row->raw_payload['account']['acct'])->not->toBe($remote['account']['acct'])
        ->and($row->raw_payload['account']['acct'])->toContain('[email]');

    expect(asTenant($company, fn () => Feedback::query()->where('author', '[email]')->count()))->toBe(0);
});

it('falls back to the username rather than storing a blank author', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    $blank = collect(mdStatuses('page-1.json'))
        ->first(fn (array $s) => $s['account']['display_name'] === '');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $blank['id'])
        ->firstOrFail());

    expect($row->author)->toBe($blank['account']['username'])
        ->and($row->author)->not->toBe('');
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — nothing persisted or logged is built from a response
|--------------------------------------------------------------------------
*/

it('writes a sync_error that carries nothing from the response body', function (int $status, string $expected) {
    mdServe(['*' => [(string) json_encode([
        'error' => 'UPSTREAM-ECHO-BODY',
        'instance' => MD_HOST,
    ]), $status]]);
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    try {
        runFetchDirect($company, $integration);
    } catch (Throwable) {
        // Transient failures are rethrown for the queue. The recorded state is
        // what this test is about.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)->toBe($expected)
        ->and($reloaded->sync_error)->not->toContain('UPSTREAM-ECHO-BODY')
        ->and($reloaded->status)->toBe('error');
})->with([
    'public preview disabled' => [401, 'The platform rejected the integration credentials.'],
    'not a mastodon endpoint' => [404, 'The integration settings are incomplete for this platform.'],
    'request refused' => [422, 'The integration settings are incomplete for this platform.'],
    'upstream down' => [500, 'The platform could not be reached.'],
]);

it('logs nothing built from the response, on the failure path or the happy one', function () {
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    $failing = mdCaptureLog(function () use ($company, $integration) {
        mdServe(['*' => [(string) json_encode(['error' => 'UPSTREAM-ECHO-BODY']), 401]]);

        try {
            runFetchDirect($company, $integration);
        } catch (Throwable) {
        }
    });

    $happy = mdCaptureLog(function () use ($company, $integration) {
        mdServeFeed();
        runFetch($company, $integration->fresh());
    });

    foreach ([$failing, $happy] as $written) {
        expect($written)->not->toContain('UPSTREAM-ECHO-BODY')
            ->and($written)->not->toContain('"credentials"');
    }

    // The failure path has to have written *something*, and the happy path has
    // to have actually been happy — otherwise both halves prove nothing.
    expect($failing)->not->toBe('')
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(MD_PAGE_SIZE);
});

it('sends no credential anywhere, because this integration holds none', function () {
    mdServeFeed();
    mastodonWired();
    [$company, $integration] = mastodonIntegration();

    runFetch($company, $integration);

    Http::assertSent(function ($request) {
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            $headers[strtolower((string) $name)] = $values;
        }

        return ! isset($headers['authorization'])
            && ! str_contains($request->url(), 'access_token')
            && $request->body() === '';
    });

    expect(asTenant($company, fn () => Integration::query()->findOrFail($integration->id))->credentials)
        ->toBe([]);
});
