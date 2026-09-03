<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\SyncCursor;
use App\Support\Connectors\TrustpilotConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Trustpilot business-unit reviews
|--------------------------------------------------------------------------
|
| Every fixture here is synthesised from Trustpilot's published documentation,
| not captured — contracts/fixtures/platforms/trustpilot/README.md says field by
| field what is documented and what is inferred. Expectations are derived from
| the fixture at run time for the same reason they are in the App Store and
| Zendesk tests: the content is replaceable, the shape is what has to hold.
|
| The connector under test is built with `perPage: 3` so that page-1.json (three
| reviews) is a *full* page and page-2-last.json (two) is a short one. That is
| the connector's end-of-feed signal, so the relation between the two counts is
| load bearing and asserted below.
|
*/

const TP_BUSINESS_UNIT_ID = '5f3a1c9b2d4e6f8a0b1c2d3e';
const TP_API_KEY = 'tpkey-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789';
const TP_PER_PAGE = 3;

function trustpilotConnector(int $maxPages = 20, int $perPage = TP_PER_PAGE): TrustpilotConnector
{
    return new TrustpilotConnector(
        businessUnitId: TP_BUSINESS_UNIT_ID,
        apiKey: TP_API_KEY,
        baseUrl: 'https://api.trustpilot.com',
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        perPage: $perPage,
    );
}

function trustpilotFake(string $file, int $status = 200): void
{
    Http::fake(['*' => Http::response(PlatformFixture::raw('trustpilot', $file), $status)]);
}

/**
 * @return list<array<string, mixed>>
 */
function trustpilotReviews(string $file): array
{
    /** @var list<array<string, mixed>> $reviews */
    $reviews = PlatformFixture::json('trustpilot', $file)['reviews'];

    return $reviews;
}

/**
 * One review out of a fixture, by id.
 *
 * @return array<string, mixed>
 */
function trustpilotReview(string $file, string $id): array
{
    foreach (trustpilotReviews($file) as $review) {
        if ($review['id'] === $id) {
            return $review;
        }
    }

    throw new RuntimeException("No review {$id} in {$file}.");
}

/**
 * The query string of the request that was sent, decoded.
 *
 * @return array<string, string>
 */
function trustpilotQuery(object $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * Headers keyed lower-case, because header names are case-insensitive on the
 * wire and this test must not pass or fail on the casing this class happens to
 * use today.
 *
 * @return array<string, list<string>>
 */
function trustpilotHeaders(object $request): array
{
    $headers = [];

    /** @var array<string, list<string>> $raw */
    $raw = $request->headers();

    foreach ($raw as $name => $values) {
        $headers[strtolower((string) $name)] = $values;
    }

    return $headers;
}

function trustpilotFailure(callable $call): ConnectorFailure
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
| The request — where the credential is allowed to appear, and the ordering
|--------------------------------------------------------------------------
*/

it('sends the api key in the apikey header and nowhere else', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector()->fetchPage(null);

    Http::assertSent(function ($request) {
        $headers = trustpilotHeaders($request);

        return ($headers['apikey'] ?? null) === [TP_API_KEY]
            // Invariant I5 at the wire. A key in the query string is written
            // into every proxy and access log between here and Trustpilot, and
            // into Trustpilot's own — a leak that never touches a log of ours
            // and is a leak all the same.
            && ! str_contains($request->url(), TP_API_KEY)
            && ! array_key_exists('apikey', trustpilotQuery($request))
            && ! array_key_exists('apiKey', trustpilotQuery($request))
            && ! isset($headers['authorization'])
            && $request->body() === '';
    });
});

it('asks the reviews endpoint of the configured business unit', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => str_starts_with(
        $request->url(),
        'https://api.trustpilot.com/v1/business-units/'.TP_BUSINESS_UNIT_ID.'/reviews?'
    ));
});

it('always asks for the feed newest-first', function (?string $cursor) {
    // Not a preference: the whole watermark model assumes newest-first. Without
    // an explicit ordering the API's default order is not guaranteed, and a
    // watermark on an unordered feed stops the run at an arbitrary point while
    // reporting that it caught up.
    trustpilotFake('page-1.json');

    trustpilotConnector()->fetchPage($cursor);

    Http::assertSent(fn ($request) => (trustpilotQuery($request)['orderBy'] ?? null) === 'createdat.desc');
})->with([
    'first run' => [null],
    'later page' => ['{"page":2}'],
    'after a watermark' => ['{"page":1,"watermark":"2026-08-01T00:00:00Z"}'],
]);

it('asks for the configured page size', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => trustpilotQuery($request)['perPage'] === (string) TP_PER_PAGE);
});

it('clamps the page size to the documented ceiling instead of sending it', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector(perPage: 500)->fetchPage(null);

    Http::assertSent(fn ($request) => trustpilotQuery($request)['perPage'] === '100');
});

it('starts the first run at page one', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => trustpilotQuery($request)['page'] === '1');
});

it('follows the cursor page instead of restarting the feed', function () {
    trustpilotFake('page-2-last.json');

    trustpilotConnector()->fetchPage('{"page":2}');

    Http::assertSent(fn ($request) => trustpilotQuery($request)['page'] === '2');
});

/*
|--------------------------------------------------------------------------
| The business unit id is substituted into the path, so it is whitelisted
|--------------------------------------------------------------------------
*/

it('refuses a business unit id that is not 24 hex characters', function (string $id) {
    // The value goes into the URL path. A value carrying `/`, `?` or `..` would
    // point the authenticated request — and the apikey header with it — at an
    // endpoint of the writer's choosing.
    $build = fn () => new TrustpilotConnector(
        businessUnitId: $id,
        apiKey: TP_API_KEY,
        baseUrl: 'https://api.trustpilot.com',
        limits: new ConnectorLimits(20, 3),
        timeout: 5,
        perPage: TP_PER_PAGE,
    );

    expect(trustpilotFailure($build))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'empty' => [''],
    'too short' => ['5f3a1c9b2d4e6f8a0b1c2d3'],
    'too long' => ['5f3a1c9b2d4e6f8a0b1c2d3ef'],
    'not hex' => ['5f3a1c9b2d4e6f8a0b1c2d3z'],
    'path traversal' => ['../../v1/private/reviews00'],
    'query injection' => ['5f3a1c9b2d4e6f8a0b1c2d3e?x=1'],
    'absolute url' => ['https://evil.test/x'],
]);

it('accepts an upper-case business unit id', function () {
    trustpilotFake('page-1.json');

    (new TrustpilotConnector(
        businessUnitId: strtoupper(TP_BUSINESS_UNIT_ID),
        apiKey: TP_API_KEY,
        baseUrl: 'https://api.trustpilot.com',
        limits: new ConnectorLimits(20, 3),
        timeout: 5,
        perPage: TP_PER_PAGE,
    ))->fetchPage(null);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        '/business-units/'.strtoupper(TP_BUSINESS_UNIT_ID).'/reviews'
    ));
});

/*
|--------------------------------------------------------------------------
| Field mapping
|--------------------------------------------------------------------------
*/

it('maps a review onto a connector item', function () {
    trustpilotFake('page-1.json');

    $review = trustpilotReview('page-1.json', '60a1b2c3d4e5f60718293a01');
    $item = collect(trustpilotConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $review['id']);

    expect($item)->not->toBeNull()
        ->and($item->author)->toBe($review['consumer']['displayName'])
        ->and($item->rating)->toBe($review['stars'])
        // createdAt, not updatedAt: an edit or a company reply must not move a
        // review forward in the inbox or in the trend charts.
        ->and($item->publishedAt)->toBe($review['createdAt'])
        ->and($review['updatedAt'])->not->toBeNull()
        ->and($item->publishedAt)->not->toBe($review['updatedAt'])
        ->and($item->sourceUrl)->toBe('https://www.trustpilot.com/reviews/'.$review['id'])
        ->and($item->rawPayload)->toBe($review);
});

it('joins the headline and the body into the one string the analyzer reads', function () {
    // Documented decision, TrustpilotConnector class docblock: `title` and
    // `text` both carry meaning — the headline is frequently where the
    // sentiment lives and the body where the reason lives — and the analyzer
    // sees feedbacks.body and nothing else, so dropping either throws away
    // signal the sentiment and category analysis is built on.
    trustpilotFake('page-1.json');

    $review = trustpilotReview('page-1.json', '60a1b2c3d4e5f60718293a01');
    $item = collect(trustpilotConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $review['id']);

    expect($review['title'])->not->toBe('')
        ->and($review['text'])->not->toBe('')
        ->and($item->body)->toBe($review['title']."\n\n".$review['text'])
        ->and($item->body)->toContain($review['title'])
        ->and($item->body)->toContain($review['text']);
});

it('uses the headline alone when the review carries no body text', function () {
    trustpilotFake('page-1.json');

    $review = trustpilotReview('page-1.json', '60a1b2c3d4e5f60718293a03');
    $item = collect(trustpilotConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $review['id']);

    expect($review['text'])->toBe('')
        ->and($item->body)->toBe($review['title']);
});

it('uses the body alone when the review carries no headline', function () {
    trustpilotFake('page-2-last.json');

    $review = trustpilotReview('page-2-last.json', '60a1b2c3d4e5f60718293a04');
    $item = collect(trustpilotConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $review['id']);

    expect($review['title'])->toBeNull()
        ->and($item->body)->toBe($review['text']);
});

it('skips a review that carries neither a headline nor a body', function () {
    // An empty row would sit in the inbox and spend a unit of analysis quota on
    // nothing at all.
    trustpilotFake('page-2-last.json');

    $review = trustpilotReview('page-2-last.json', '60a1b2c3d4e5f60718293a05');
    $page = trustpilotConnector()->fetchPage(null);

    expect($review['title'])->toBe('')
        ->and($review['text'])->toBeNull()
        ->and(collect($page->items)->pluck('externalId')->all())->not->toContain($review['id'])
        ->and($page->items)->toHaveCount(count(trustpilotReviews('page-2-last.json')) - 1);
});

it('carries every ingestable external id from the recorded page', function () {
    trustpilotFake('page-1.json');

    $expected = array_map(
        static fn (array $review): string => $review['id'],
        trustpilotReviews('page-1.json'),
    );

    $page = trustpilotConnector()->fetchPage(null);

    expect(array_map(fn ($item) => $item->externalId, $page->items))->toBe($expected)
        ->and(array_unique($expected))->toHaveCount(count($expected));
});

it('keeps the star rating on the scale the product already speaks', function (mixed $stars, ?int $expected) {
    $review = trustpilotReview('page-1.json', '60a1b2c3d4e5f60718293a01');
    $review['stars'] = $stars;

    Http::fake(['*' => Http::response(json_encode(['reviews' => [$review]]), 200)]);

    expect(trustpilotConnector()->fetchPage(null)->items[0]->rating)->toBe($expected);
})->with([
    'one star' => [1, 1],
    'five stars' => [5, 5],
    'numeric string' => ['4', 4],
    'zero is not a rating' => [0, null],
    'above the scale' => [6, null],
    'absent' => [null, null],
    'not a number' => ['good', null],
]);

it('tolerates a review with no consumer block', function () {
    $review = trustpilotReview('page-1.json', '60a1b2c3d4e5f60718293a01');
    unset($review['consumer']);

    Http::fake(['*' => Http::response(json_encode(['reviews' => [$review]]), 200)]);

    expect(trustpilotConnector()->fetchPage(null)->items[0]->author)->toBeNull();
});

it('skips a review with no id rather than failing the page', function () {
    $reviews = trustpilotReviews('page-1.json');
    $broken = $reviews[0];
    unset($broken['id']);

    Http::fake(['*' => Http::response(json_encode(['reviews' => [$broken, $reviews[1]]]), 200)]);

    $page = trustpilotConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($reviews[1]['id']);
});

/*
|--------------------------------------------------------------------------
| Paging — a full page continues, a short page ends, an empty page ends
|--------------------------------------------------------------------------
*/

it('continues past a full page', function () {
    trustpilotFake('page-1.json');

    $page = trustpilotConnector()->fetchPage(null);

    expect(trustpilotReviews('page-1.json'))->toHaveCount(TP_PER_PAGE)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->not->toBeNull()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(2);
});

it('ends the run on a short page and rewinds to the top of the feed', function () {
    trustpilotFake('page-2-last.json');

    $page = trustpilotConnector()->fetchPage('{"page":2}');

    expect(count(trustpilotReviews('page-2-last.json')))->toBeLessThan(TP_PER_PAGE)
        ->and($page->hasMore)->toBeFalse()
        // Newest-first, so the next run has to start at the top again and stop
        // at the watermark this run established.
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(1);
});

it('ends the run on an empty page', function () {
    trustpilotFake('page-empty.json');

    $page = trustpilotConnector()->fetchPage('{"page":3}');

    expect(trustpilotReviews('page-empty.json'))->toBeEmpty()
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse()
        ->and($page->watermark)->toBeNull()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(1);
});

it('leaves the stored watermark untouched across an empty page', function () {
    trustpilotFake('page-empty.json');

    $watermark = '2026-08-20T00:00:00+00:00';
    $page = trustpilotConnector()->fetchPage(json_encode(['page' => 2, 'watermark' => $watermark]));

    expect(SyncCursor::decode($page->nextCursor)->watermark)->toBe($watermark);
});

it('never reports the stream ended just because it reached the runners page cap', function () {
    // Unlike the App Store feed, Trustpilot publishes no page-depth ceiling. A
    // connector that answered hasMore=false on reaching maxPagesPerRun would be
    // telling IngestionRunner the run *completed*, which lets it promote a
    // watermark it never reached and buries every unfetched page below it
    // forever (docs/LESSONS.md, empty-middle-page and capped-run entries). The
    // cap is the runner's runaway-loop ceiling and stays the runner's to apply.
    trustpilotFake('page-1.json');

    $page = trustpilotConnector(maxPages: 1)->fetchPage('{"page":1}');

    expect($page->hasMore)->toBeTrue()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(2);
});

it('does not clamp the requested page to the runners cap', function () {
    trustpilotFake('page-1.json');

    trustpilotConnector(maxPages: 2)->fetchPage('{"page":7}');

    // The cap bounds how many pages one run fetches, not how deep the feed may
    // be read: a capped run resumes exactly where it stopped.
    Http::assertSent(fn ($request) => trustpilotQuery($request)['page'] === '7');
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — a full re-scan is forbidden
|--------------------------------------------------------------------------
*/

it('publishes the newest createdAt on the page as the watermark, and only in pending', function () {
    trustpilotFake('page-1.json');

    $newest = max(array_map(
        fn (array $review) => SyncCursor::parse($review['createdAt'])?->getTimestamp(),
        trustpilotReviews('page-1.json'),
    ));

    $page = trustpilotConnector()->fetchPage(null);
    $next = SyncCursor::decode($page->nextCursor);

    // Mid-run the newest timestamp rides in `pending`, not in `watermark`.
    // Advancing `watermark` between pages of one run is what made a
    // newest-first feed cut itself off after page 1: every item on page 2 is
    // older than the value page 1 wrote, so alreadySeen() reported true for all
    // of them. Promotion is IngestionRunner's call, at the end of the run.
    expect(SyncCursor::parse($page->watermark)?->getTimestamp())->toBe($newest)
        ->and(SyncCursor::parse($next->pending)?->getTimestamp())->toBe($newest)
        ->and($next->watermark)->toBeNull()
        ->and(SyncCursor::parse($next->promoted()->watermark)?->getTimestamp())->toBe($newest);
});

it('stops the run as soon as it reaches the previous watermark', function () {
    trustpilotFake('page-1.json');

    $newest = collect(trustpilotReviews('page-1.json'))
        ->sortByDesc(fn (array $review) => SyncCursor::parse($review['createdAt'])?->getTimestamp())
        ->first()['createdAt'];

    $page = trustpilotConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $newest]));

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse();
});

it('keeps only the reviews newer than the watermark', function () {
    trustpilotFake('page-1.json');

    $sorted = collect(trustpilotReviews('page-1.json'))
        ->sortByDesc(fn (array $review) => SyncCursor::parse($review['createdAt'])?->getTimestamp())
        ->values();

    // The middle of a newest-first page: everything above it is new, everything
    // from it downwards was ingested by an earlier run.
    $watermark = $sorted[1]['createdAt'];

    $page = trustpilotConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $watermark]));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($sorted[0]['id'])
        ->and($page->hasMore)->toBeFalse();
});

it('keeps the encoded cursor inside the varchar(255) column', function () {
    trustpilotFake('page-1.json');

    $page = trustpilotConnector()->fetchPage(null);

    expect(strlen((string) $page->nextCursor))->toBeLessThan(255);
});

/*
|--------------------------------------------------------------------------
| Failure mapping
|--------------------------------------------------------------------------
*/

it('maps the documented error bodies onto connector failures', function (string $file, int $status, ConnectorFailure $expected) {
    trustpilotFake($file, $status);

    expect(trustpilotFailure(fn () => trustpilotConnector()->fetchPage(null)))->toBe($expected);
})->with([
    'unrecognised key' => ['error-unauthorized.json', 401, ConnectorFailure::InvalidCredentials],
    'key cannot read this business unit' => ['error-forbidden.json', 403, ConnectorFailure::InvalidCredentials],
    'no such business unit' => ['error-not-found.json', 404, ConnectorFailure::Misconfigured],
    'throttled' => ['error-rate-limited.json', 429, ConnectorFailure::RateLimited],
]);

it('maps the remaining status codes onto connector failures', function (int $status, ConnectorFailure $expected) {
    Http::fake(['*' => Http::response('{}', $status)]);

    expect(trustpilotFailure(fn () => trustpilotConnector()->fetchPage(null)))->toBe($expected);
})->with([
    [400, ConnectorFailure::Unreachable],
    [500, ConnectorFailure::Unreachable],
    [502, ConnectorFailure::Unreachable],
    [503, ConnectorFailure::Unreachable],
]);

it('treats a connection failure as unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(trustpilotFailure(fn () => trustpilotConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::Unreachable);
});

it('refuses a body it cannot recognise as a review page', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(trustpilotFailure(fn () => trustpilotConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'not json' => ['not json'],
    'a list' => ['[]'],
    'no reviews key' => ['{"links":[]}'],
    'reviews is not a list' => ['{"reviews":"nope"}'],
    'reviews is a number' => ['{"reviews":7}'],
]);

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential never reaches anything that can be persisted
|--------------------------------------------------------------------------
*/

it('never puts the credential into a failure, its message or its stack trace', function (int $status) {
    Http::fake(['*' => Http::response(
        // The worst case: an upstream that echoes the credential straight back.
        json_encode(['message' => 'rejected', 'sent' => TP_API_KEY]),
        $status
    )]);

    try {
        trustpilotConnector()->fetchPage(null);
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        expect($e->getMessage())->not->toContain(TP_API_KEY)
            ->and($e->getSafeMessage())->not->toContain(TP_API_KEY)
            // PHP puts scalar call arguments into a trace. The key is a
            // constructor argument, so no frame on this stack carries it — but
            // the assertion is cheap and the failure mode is silent.
            ->and($e->getTraceAsString())->not->toContain(TP_API_KEY);
    }
})->with([401, 403, 404, 429, 500]);

/*
|--------------------------------------------------------------------------
| Health and limits
|--------------------------------------------------------------------------
*/

it('reports itself healthy when the endpoint answers', function () {
    trustpilotFake('page-1.json');

    expect(trustpilotConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports itself healthy on an empty business unit', function () {
    // A brand-new business unit with no reviews yet is configured correctly.
    trustpilotFake('page-empty.json');

    expect(trustpilotConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports the safe failure message when the key is rejected', function () {
    trustpilotFake('error-unauthorized.json', 401);

    $health = trustpilotConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::InvalidCredentials)
        ->and($health->message())->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
        ->and($health->message())->not->toContain(TP_API_KEY);
});

it('reports unhealthy when the body cannot be recognised at all', function () {
    Http::fake(['*' => Http::response('<html>maintenance</html>', 200)]);

    $health = trustpilotConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::MalformedResponse);
});

it('exposes the configured ceilings', function () {
    $limits = trustpilotConnector(maxPages: 20)->limits();

    expect($limits->maxPagesPerRun)->toBe(20)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The fixtures themselves
|--------------------------------------------------------------------------
|
| Every byte is synthesised from documentation (see
| contracts/fixtures/platforms/trustpilot/README.md). This repository is public
| and has already paid once, with a full history rewrite, for fixtures holding
| real reviewer names and profile URLs. The promise is asserted, not stated.
|
*/

it('holds no real person, business or credential in any fixture', function (string $file) {
    foreach (trustpilotReviews($file) as $review) {
        expect($review['consumer']['displayName'])->toMatch('/^reviewer-\d{2}$/')
            ->and($review['id'])->toMatch('/^[a-f0-9]{24}$/')
            ->and($review['consumer']['id'])->toMatch('/^[a-f0-9]{24}$/')
            ->and($review['businessUnit']['id'])->toBe(TP_BUSINESS_UNIT_ID)
            // RFC 2606 reserves .invalid, so it can never resolve.
            ->and($review['businessUnit']['identifyingName'])->toEndWith('.invalid')
            // Trustpilot's own field for the invitation address. Never a value.
            ->and($review['referralEmail'])->toBeNull();
    }

    expect(PlatformFixture::raw('trustpilot', $file))
        ->not->toContain('@')
        ->not->toContain('trustpilot.com/evaluate');
})->with(['page-1.json', 'page-2-last.json']);

it('keeps the recorded pages ordered newest-first, with page one ahead of page two', function () {
    $timestamps = fn (string $file) => array_map(
        fn (array $review) => SyncCursor::parse($review['createdAt'])?->getTimestamp(),
        trustpilotReviews($file),
    );

    $first = $timestamps('page-1.json');
    $second = $timestamps('page-2-last.json');

    $descending = fn (array $values) => $values === array_values(array_reverse(collect($values)->sort()->values()->all()));

    // The watermark tests read the two pages as one newest-first stream, so
    // this relation is load bearing.
    expect($descending($first))->toBeTrue()
        ->and($descending($second))->toBeTrue()
        ->and(min($first))->toBeGreaterThan(max($second));
});

it('keeps the documented key set on every recorded review', function (string $file) {
    foreach (trustpilotReviews($file) as $review) {
        expect(array_keys($review))->toBe([
            'links', 'id', 'stars', 'title', 'text', 'language', 'location',
            'createdAt', 'updatedAt', 'experiencedAt', 'referralEmail',
            'referenceId', 'companyReply', 'isVerified', 'numberOfLikes',
            'status', 'reportData', 'complianceLabels', 'countsTowardsTrustScore',
            'countsTowardsLocationTrustScore', 'invitation', 'source',
            'consumer', 'businessUnit',
        ])
            // ISO 8601 in UTC, as documented. The runner stores published_at
            // with toIso8601String() so the offset survives into timestamptz.
            ->and($review['createdAt'])->toEndWith('Z');
    }
})->with(['page-1.json', 'page-2-last.json']);

it('keeps page one full and page two short against the page size these tests use', function () {
    // The connector's end-of-feed signal is count(reviews) < perPage, so these
    // two counts are what make "continues" and "ends" mean anything above.
    expect(trustpilotReviews('page-1.json'))->toHaveCount(TP_PER_PAGE)
        ->and(count(trustpilotReviews('page-2-last.json')))->toBeLessThan(TP_PER_PAGE)
        ->and(trustpilotReviews('page-empty.json'))->toBeEmpty();
});
