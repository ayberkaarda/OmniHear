<?php

use App\Support\Connectors\AppStoreConnector;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\SyncCursor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| App Store RSS connector
|--------------------------------------------------------------------------
|
| Every expectation is derived from the fixture at run time rather than written
| out as a literal. The recorded pages hold real captured review text that is
| being replaced with synthetic values; an assertion on a specific reviewer's
| name would break on that swap while proving nothing about the connector. The
| envelope is what has to hold, so the envelope is what is asserted.
|
*/

const APPSTORE_APP_ID = '324684580';

function appStoreConnector(int $maxPages = 10): AppStoreConnector
{
    return new AppStoreConnector(
        appId: APPSTORE_APP_ID,
        country: 'TR',
        baseUrl: 'https://itunes.apple.com',
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
    );
}

function appStoreFeed(string $file): void
{
    Http::fake(['*' => Http::response(PlatformFixture::raw('appstore', $file), 200)]);
}

/**
 * @return list<string>
 */
function appStoreTimestamps(string $file): array
{
    return array_map(
        static fn (array $entry): string => $entry['updated']['label'],
        PlatformFixture::appStoreEntries($file),
    );
}

function appStoreFailure(callable $call): ConnectorFailure
{
    try {
        $call();
    } catch (ConnectorException $e) {
        return $e->failure();
    }

    throw new RuntimeException('Expected a ConnectorException, none was thrown.');
}

/*
|--------------------------------------------------------------------------
| Field mapping and the request itself
|--------------------------------------------------------------------------
*/

it('maps every entry of a recorded page onto a connector item', function () {
    appStoreFeed('page-full.json');

    $entries = PlatformFixture::appStoreEntries('page-full.json');
    $page = appStoreConnector()->fetchPage(null);

    expect($entries)->not->toBeEmpty()
        ->and($page->items)->toHaveCount(count($entries));

    $first = $entries[0];
    $item = $page->items[0];

    expect($item->externalId)->toBe($first['id']['label'])
        ->and($item->author)->toBe($first['author']['name']['label'])
        ->and($item->body)->toBe($first['content']['label'])
        ->and($item->publishedAt)->toBe($first['updated']['label'])
        ->and($item->rating)->toBe((int) $first['im:rating']['label'])
        ->and($item->sourceUrl)->toBe($first['link']['attributes']['href'])
        ->and($item->rawPayload)->toBe($first);
});

it('carries every external id from the recorded page', function () {
    appStoreFeed('page-full.json');

    $expected = array_map(
        static fn (array $entry): string => $entry['id']['label'],
        PlatformFixture::appStoreEntries('page-full.json'),
    );

    $page = appStoreConnector()->fetchPage(null);

    expect(array_map(fn ($item) => $item->externalId, $page->items))->toBe($expected)
        ->and(array_unique($expected))->toHaveCount(count($expected));
});

it('asks for the newest-first feed of the configured app and storefront', function () {
    appStoreFeed('page-full.json');

    appStoreConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => $request->url() ===
        'https://itunes.apple.com/tr/rss/customerreviews/page=1/id='.APPSTORE_APP_ID.'/sortBy=mostRecent/json');
});

it('follows the cursor page instead of restarting the feed', function () {
    appStoreFeed('page-full.json');

    appStoreConnector()->fetchPage('{"page":4}');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page=4/'));
});

/*
|--------------------------------------------------------------------------
| Verified behaviour 3 (PROGRESS 2026-09-02): the feed needs no credentials
|--------------------------------------------------------------------------
*/

it('sends no authorization material at all', function () {
    appStoreFeed('page-full.json');

    appStoreConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('X-Api-Key')
        && $request->body() === '');
});

/*
|--------------------------------------------------------------------------
| Verified behaviour 1 (PROGRESS 2026-09-02): page depth is capped at 10
|--------------------------------------------------------------------------
*/

it('reads the status code of the depth-limit response rather than its body', function () {
    // The recorded body is gzip'd plain text, not JSON. Parsing it would look
    // like a corrupt payload; the 400 is the actual signal.
    Http::fake(['*' => Http::response(PlatformFixture::raw('appstore', 'page-depth-exceeded.txt'), 400)]);

    $failure = appStoreFailure(fn () => appStoreConnector(maxPages: 11)->fetchPage('{"page":11}'));

    expect($failure)->toBe(ConnectorFailure::DepthLimitExceeded);
});

it('never asks for a page past the platform depth limit', function () {
    appStoreFeed('page-full.json');

    // A cursor that somehow points past the ceiling is clamped, not sent: the
    // request that would answer 400 is never issued.
    appStoreConnector()->fetchPage('{"page":97}');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page=10/'));
});

it('ends the run at the last reachable page', function () {
    appStoreFeed('page-full.json');

    $page = appStoreConnector()->fetchPage('{"page":10}');

    expect($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Verified behaviour 2 (PROGRESS 2026-09-02): pages come back empty at random
|--------------------------------------------------------------------------
*/

it('keeps the stream open when a page comes back empty', function () {
    // Measured: page=1 returned 0 entries once and 50 on the five retries that
    // followed. Treating an empty page as the end of the stream silently loses
    // every review behind it.
    appStoreFeed('page-empty-transient.json');

    $page = appStoreConnector()->fetchPage(null);

    expect(PlatformFixture::appStoreEntries('page-empty-transient.json'))->toBeEmpty()
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeTrue()
        ->and($page->watermark)->toBeNull()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(2);
});

it('leaves the watermark untouched across an empty page', function () {
    appStoreFeed('page-empty-transient.json');

    $watermark = '2026-08-20T00:00:00+00:00';
    $page = appStoreConnector()->fetchPage(json_encode(['page' => 2, 'watermark' => $watermark]));

    expect(SyncCursor::decode($page->nextCursor)->watermark)->toBe($watermark);
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — a full re-scan is forbidden
|--------------------------------------------------------------------------
*/

it('publishes the newest timestamp on the page as the watermark', function () {
    appStoreFeed('page-full.json');

    $newest = max(array_map(
        fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp(),
        appStoreTimestamps('page-full.json'),
    ));

    $page = appStoreConnector()->fetchPage(null);
    $next = SyncCursor::decode($page->nextCursor);

    // Mid-run the newest timestamp rides in `pending`, not in `watermark`.
    // Advancing `watermark` between pages of one run is what made a newest-first
    // feed cut itself off after page 1: every item on page 2 is older than the
    // value page 1 wrote, so alreadySeen() reported true for all of them.
    // `watermark` therefore stays frozen for the whole run, and `promoted()`
    // folds `pending` into it when the run ends.
    expect(SyncCursor::parse($page->watermark)?->getTimestamp())->toBe($newest)
        ->and(SyncCursor::parse($next->pending)?->getTimestamp())->toBe($newest)
        ->and($next->watermark)->toBeNull()
        ->and(SyncCursor::parse($next->promoted()->watermark)?->getTimestamp())->toBe($newest);
});

it('stops the run as soon as it reaches the previous watermark', function () {
    appStoreFeed('page-full.json');

    $newest = collect(appStoreTimestamps('page-full.json'))
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->first();

    $page = appStoreConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $newest]));

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse();
});

it('keeps only the entries newer than the watermark', function () {
    appStoreFeed('page-full.json');

    $timestamps = collect(appStoreTimestamps('page-full.json'))
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->values();

    // Halfway down a newest-first page: everything above it is new, everything
    // from it downwards was ingested by an earlier run.
    $watermark = $timestamps[10];
    $expected = $timestamps
        ->filter(fn (string $timestamp) => SyncCursor::parse($timestamp) > SyncCursor::parse($watermark))
        ->count();

    $page = appStoreConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $watermark]));

    expect($page->items)->toHaveCount($expected)
        ->and($page->hasMore)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Failure mapping
|--------------------------------------------------------------------------
*/

it('maps upstream status codes onto connector failures', function (int $status, ConnectorFailure $expected) {
    Http::fake(['*' => Http::response('{}', $status)]);

    expect(appStoreFailure(fn () => appStoreConnector()->fetchPage(null)))->toBe($expected);
})->with([
    [400, ConnectorFailure::DepthLimitExceeded],
    [401, ConnectorFailure::InvalidCredentials],
    [403, ConnectorFailure::InvalidCredentials],
    [429, ConnectorFailure::RateLimited],
    [500, ConnectorFailure::Unreachable],
    [503, ConnectorFailure::Unreachable],
]);

it('treats a connection failure as unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(appStoreFailure(fn () => appStoreConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::Unreachable);
});

it('refuses a body it cannot recognise as a feed', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(appStoreFailure(fn () => appStoreConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
})->with(['not json', '[]', '{"feed":"nope"}', '{"feed":{"entry":"nope"}}']);

it('accepts a feed whose single review is serialised as an object', function () {
    $entry = PlatformFixture::appStoreEntries('page-full.json')[0];

    Http::fake(['*' => Http::response(json_encode(['feed' => ['entry' => $entry]]), 200)]);

    $page = appStoreConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($entry['id']['label']);
});

it('skips an entry with no id rather than failing the page', function () {
    $entry = PlatformFixture::appStoreEntries('page-full.json')[0];
    $broken = $entry;
    unset($broken['id']);

    Http::fake(['*' => Http::response(json_encode(['feed' => ['entry' => [$broken, $entry]]]), 200)]);

    $page = appStoreConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($entry['id']['label']);
});

it('tolerates an entry with no rating and no content', function () {
    $entry = PlatformFixture::appStoreEntries('page-full.json')[0];
    unset($entry['im:rating'], $entry['content'], $entry['author'], $entry['link']);

    Http::fake(['*' => Http::response(json_encode(['feed' => ['entry' => [$entry]]]), 200)]);

    $item = appStoreConnector()->fetchPage(null)->items[0];

    expect($item->rating)->toBeNull()
        ->and($item->body)->toBe('')
        ->and($item->author)->toBeNull()
        ->and($item->sourceUrl)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/

it('reports itself healthy when the feed answers', function () {
    appStoreFeed('page-full.json');

    expect(appStoreConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports the safe failure message when the feed does not answer', function () {
    Http::fake(['*' => Http::response('{}', 503)]);

    $health = appStoreConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->message())->toBe(ConnectorFailure::Unreachable->safeMessage());
});

it('exposes the measured platform ceilings', function () {
    $limits = appStoreConnector()->limits();

    expect($limits->maxPagesPerRun)->toBe(10)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});
