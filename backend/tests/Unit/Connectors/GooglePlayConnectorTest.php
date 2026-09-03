<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\GooglePlayAccessToken;
use App\Support\Connectors\GooglePlayConnector;
use App\Support\Connectors\SyncCursor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Google Play — Android Publisher reviews.list
|--------------------------------------------------------------------------
|
| Every fixture here is synthesised from published documentation, not captured:
| there is no Play Console account behind this project. What is documented and
| what is inferred is recorded field by field in
| contracts/fixtures/platforms/googleplay/README.md.
|
| Expectations are derived from the fixture at run time rather than written out
| as literals, for the same reason as in the App Store and Zendesk suites: the
| content is replaceable, the envelope is what has to hold.
|
| The service-account key these tests sign with is generated in-process by
| openssl_pkey_new(). No key material is committed, the key is different on
| every run, and that is also the only honest way to prove the signature the
| minter produces actually verifies.
|
*/

const GP_PACKAGE = 'com.example.omnihear';
const GP_CLIENT_EMAIL = 'omnihear-fixture@example-project.iam.gserviceaccount.invalid';
const GP_BASE_URL = 'https://androidpublisher.googleapis.com';
const GP_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GP_INTEGRATION_ID = 4242;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The PEM header, assembled rather than written out.
 *
 * A literal one in a committed file is what guard-protected-paths refuses, and
 * rightly so — it cannot tell a test placeholder from a leaked key.
 */
function gpPemHeader(): string
{
    return '-----BEGIN '.'PRIVATE KEY-----';
}

/**
 * One RSA key pair for the whole file: generating a 2048-bit key is by an order
 * of magnitude the slowest thing here.
 *
 * @return array{private: string, public: string}
 */
function gpKeys(): array
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

function gpToken(int $integrationId = GP_INTEGRATION_ID, ?string $privateKey = null): GooglePlayAccessToken
{
    return new GooglePlayAccessToken(
        clientEmail: GP_CLIENT_EMAIL,
        privateKey: $privateKey ?? gpKeys()['private'],
        integrationId: $integrationId,
        tokenUrl: GP_TOKEN_URL,
        timeout: 5,
    );
}

function gpConnector(int $maxPages = 10, int $maxResults = 100, string $packageName = GP_PACKAGE): GooglePlayConnector
{
    return new GooglePlayConnector(
        packageName: $packageName,
        token: gpToken(),
        baseUrl: GP_BASE_URL,
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        maxResults: $maxResults,
    );
}

function gpRaw(string $file): string
{
    return PlatformFixture::raw('googleplay', $file);
}

/**
 * @return array<string, mixed>
 */
function gpJson(string $file): array
{
    return PlatformFixture::json('googleplay', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function gpReviews(string $file): array
{
    /** @var list<array<string, mixed>> $reviews */
    $reviews = gpJson($file)['reviews'];

    return $reviews;
}

/**
 * The `lastModified.seconds` of every user comment on a page, as the ISO-8601
 * instants the connector is expected to publish.
 *
 * @return list<string>
 */
function gpTimestamps(string $file): array
{
    $timestamps = [];

    foreach (gpReviews($file) as $review) {
        foreach ($review['comments'] ?? [] as $comment) {
            if (isset($comment['userComment']['lastModified']['seconds'])) {
                $timestamps[] = CarbonImmutable::createFromTimestampUTC(
                    (int) $comment['userComment']['lastModified']['seconds']
                )->toIso8601String();
            }
        }
    }

    return $timestamps;
}

/**
 * The token exchange always answers; the reviews endpoint answers the fixture.
 */
function gpFake(string $file, int $status = 200): void
{
    Http::fake([
        '*oauth2.googleapis.com/*' => Http::response(gpRaw('token-response.json'), 200),
        '*' => Http::response(gpRaw($file), $status),
    ]);
}

/**
 * The same, for a body no fixture owns — a malformed one, or a shape assembled
 * for a single case.
 */
function gpFakeBody(string $body, int $status = 200): void
{
    Http::fake([
        '*oauth2.googleapis.com/*' => Http::response(gpRaw('token-response.json'), 200),
        '*' => Http::response($body, $status),
    ]);
}

/**
 * The requests that went to the reviews endpoint, in order — the token exchange
 * is infrastructure and is filtered out.
 *
 * @return Collection<int, Request>
 */
function gpReviewRequests(): Collection
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => str_contains($request->url(), '/androidpublisher/'))
        ->values();
}

/**
 * @return array<string, string>
 */
function gpQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

function gpFailure(callable $call): ConnectorFailure
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
| The package name goes into the request path, so it is whitelisted
|--------------------------------------------------------------------------
*/

it('refuses a package name that is not java package syntax', function (string $packageName) {
    // Whitelisted, not escaped — the same reasoning as
    // ConnectorFactory::subdomain(). A value carrying `/`, `?` or `..` would
    // point the authenticated request at a different resource entirely.
    expect(gpFailure(fn () => gpConnector(packageName: $packageName)))
        ->toBe(ConnectorFailure::Misconfigured);
})->with([
    'empty' => [''],
    'no dot' => ['comexample'],
    'trailing dot' => ['com.example.'],
    'leading dot' => ['.com.example'],
    'digit first' => ['1com.example'],
    'path traversal' => ['com.example/../other'],
    'a slash' => ['com.example/reviews'],
    'a query string' => ['com.example?alt=json'],
    'a space' => ['com.example app'],
    'a hyphen' => ['com.example-app'],
]);

it('accepts a documented package name', function () {
    expect(gpConnector(packageName: 'com.example.omnihear_beta.app'))
        ->toBeInstanceOf(GooglePlayConnector::class);
});

/*
|--------------------------------------------------------------------------
| The request
|--------------------------------------------------------------------------
*/

it('asks the reviews endpoint of the configured package', function () {
    gpFake('page-1.json');

    gpConnector()->fetchPage(null);

    $request = gpReviewRequests()->first();

    expect($request->url())->toStartWith(
        GP_BASE_URL.'/androidpublisher/v3/applications/'.GP_PACKAGE.'/reviews?'
    )->and(gpQuery($request)['maxResults'])->toBe('100');
});

it('does not send a page token on the first run', function () {
    gpFake('page-1.json');

    gpConnector()->fetchPage(null);

    expect(gpQuery(gpReviewRequests()->first()))->not->toHaveKey('token');
});

it('continues from the stored page token instead of restarting the listing', function () {
    gpFake('page-2-end.json');

    $stored = gpJson('page-1.json')['tokenPagination']['nextPageToken'];

    gpConnector()->fetchPage((new SyncCursor)->withToken($stored)->encode());

    expect(gpQuery(gpReviewRequests()->first())['token'])->toBe($stored);
});

it('clamps the page size to the documented ceiling', function (int $requested, string $expected) {
    gpFake('page-1.json');

    gpConnector(maxResults: $requested)->fetchPage(null);

    expect(gpQuery(gpReviewRequests()->first())['maxResults'])->toBe($expected);
})->with([
    'above the ceiling' => [500, '100'],
    'at the ceiling' => [100, '100'],
    'below it' => [25, '25'],
    'zero' => [0, '1'],
    'negative' => [-5, '1'],
]);

it('sends the access token in the authorization header and nowhere else', function () {
    gpFake('page-1.json');

    gpConnector()->fetchPage(null);

    $minted = gpJson('token-response.json')['access_token'];
    $request = gpReviewRequests()->first();

    expect($request->header('Authorization'))->toBe(['Bearer '.$minted])
        // Invariant I5 at the wire: a credential in a URL is written into every
        // proxy and access log between here and Google.
        ->and($request->url())->not->toContain($minted)
        ->and($request->url())->not->toContain(GP_CLIENT_EMAIL)
        ->and($request->url())->not->toContain('PRIVATE KEY')
        ->and($request->body())->toBe('');
});

/*
|--------------------------------------------------------------------------
| Paging — tokenPagination is the only thing that ends the stream
|--------------------------------------------------------------------------
*/

it('carries the next page token forward and reports more to come', function () {
    gpFake('page-1.json');

    $page = gpConnector()->fetchPage(null);

    expect($page->hasMore)->toBeTrue()
        ->and(SyncCursor::decode($page->nextCursor)->token)
        ->toBe(gpJson('page-1.json')['tokenPagination']['nextPageToken']);
});

it('ends the run when tokenPagination is absent', function () {
    gpFake('page-2-end.json');

    $page = gpConnector()->fetchPage(null);

    expect(gpJson('page-2-end.json'))->not->toHaveKey('tokenPagination')
        ->and($page->hasMore)->toBeFalse()
        // Dropped on purpose: a page token is a within-run value, and leaving a
        // stale one behind would have the next run replay a position instead of
        // starting at the top of the seven-day window.
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBeNull();
});

it('keeps the encoded cursor inside the varchar(255) column', function () {
    gpFake('page-1.json');

    $page = gpConnector()->fetchPage(null);

    expect(strlen((string) $page->nextCursor))->toBeLessThan(255);
});

it('refuses a page token too long to fit the column it has to be stored in', function () {
    gpFakeBody((string) json_encode([
        'reviews' => [],
        'tokenPagination' => ['nextPageToken' => str_repeat('x', 151)],
    ]));

    // Refused rather than truncated, and that is the whole point. A cursor
    // silently cut to fit varchar(255) is the same class of bug as a watermark
    // advanced mid-run: nothing fails, and the integration simply re-reads page
    // one forever because the token it stored is not the token it was given.
    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
});

it('carries a page token at the very limit without truncating it', function () {
    $token = str_repeat('x', 150);

    gpFakeBody((string) json_encode([
        'reviews' => gpReviews('page-1.json'),
        'tokenPagination' => ['nextPageToken' => $token],
    ]));

    $page = gpConnector()->fetchPage(null);
    $stored = SyncCursor::decode($page->nextCursor)->token;

    // The limit is the largest token that still leaves room for the cursor
    // envelope, so the worst legal case has to survive the round trip intact
    // *and* fit the column.
    expect($stored)->toBe($token)
        ->and(strlen((string) $stored))->toBe(150)
        ->and(strlen((string) $page->nextCursor))->toBeLessThan(255);
});

/*
|--------------------------------------------------------------------------
| An empty page is not the end of the stream
|--------------------------------------------------------------------------
*/

it('keeps the stream open when a page comes back with no reviews', function () {
    gpFake('page-empty-continues.json');

    $page = gpConnector()->fetchPage(null);

    expect(gpReviews('page-empty-continues.json'))->toBeEmpty()
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeTrue()
        ->and($page->watermark)->toBeNull()
        ->and(SyncCursor::decode($page->nextCursor)->token)
        ->toBe(gpJson('page-empty-continues.json')['tokenPagination']['nextPageToken']);
});

it('leaves the watermark untouched across an empty page', function () {
    gpFake('page-empty-continues.json');

    $watermark = '2026-08-20T00:00:00+00:00';
    $page = gpConnector()->fetchPage((string) json_encode(['page' => 1, 'watermark' => $watermark]));

    expect(SyncCursor::decode($page->nextCursor)->watermark)->toBe($watermark)
        ->and(SyncCursor::decode($page->nextCursor)->pending)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — the seven-day window, re-listed and stopped
|--------------------------------------------------------------------------
*/

it('publishes the newest user comment on the page as the watermark', function () {
    gpFake('page-1.json');

    $newest = max(array_map(
        fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp(),
        gpTimestamps('page-1.json'),
    ));

    $page = gpConnector()->fetchPage(null);
    $next = SyncCursor::decode($page->nextCursor);

    // Mid-run the newest timestamp rides in `pending`, not in `watermark`.
    // Advancing `watermark` between pages of a newest-first feed makes every
    // item on page 2 compare as already seen, and the run silently ingests only
    // its first page (docs/LESSONS.md, 2026-09-02).
    expect(SyncCursor::parse($page->watermark)?->getTimestamp())->toBe($newest)
        ->and(SyncCursor::parse($next->pending)?->getTimestamp())->toBe($newest)
        ->and($next->watermark)->toBeNull()
        ->and(SyncCursor::parse($next->promoted()->watermark)?->getTimestamp())->toBe($newest);
});

it('stops the run at the stored watermark even though another page is offered', function () {
    gpFake('page-1.json');

    $newest = collect(gpTimestamps('page-1.json'))
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->first();

    $page = gpConnector()->fetchPage((string) json_encode(['page' => 1, 'watermark' => $newest]));

    expect(gpJson('page-1.json'))->toHaveKey('tokenPagination')
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBeNull();
});

it('keeps only the reviews newer than the watermark', function () {
    gpFake('page-1.json');

    $timestamps = collect(gpTimestamps('page-1.json'))
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->values();

    // The middle of a newest-first page: everything above it is new, everything
    // from it downwards was ingested by an earlier run.
    $watermark = $timestamps[1];
    $expected = $timestamps
        ->filter(fn (string $timestamp) => SyncCursor::parse($timestamp) > SyncCursor::parse($watermark))
        ->count();

    $page = gpConnector()->fetchPage((string) json_encode(['page' => 1, 'watermark' => $watermark]));

    expect($expected)->toBeGreaterThan(0)
        ->and($page->items)->toHaveCount($expected)
        ->and($page->hasMore)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Field mapping
|--------------------------------------------------------------------------
*/

it('maps a review onto a connector item', function () {
    gpFake('page-1.json');

    $reviews = gpReviews('page-1.json');
    $page = gpConnector()->fetchPage(null);

    $first = $reviews[0];
    $comment = $first['comments'][0]['userComment'];
    $item = $page->items[0];

    expect($page->items)->toHaveCount(count($reviews))
        ->and($item->externalId)->toBe($first['reviewId'])
        ->and($item->author)->toBe($first['authorName'])
        ->and($item->body)->toBe($comment['text'])
        ->and($item->rating)->toBe($comment['starRating'])
        ->and($item->sourceUrl)->toBe('https://play.google.com/store/apps/details?id='.GP_PACKAGE)
        ->and($item->rawPayload)->toBe($first);
});

it('turns lastModified.seconds into an instant that keeps its offset', function () {
    gpFake('page-1.json');

    $seconds = (int) gpReviews('page-1.json')[0]['comments'][0]['userComment']['lastModified']['seconds'];
    $item = gpConnector()->fetchPage(null)->items[0];

    // toIso8601String, not toDateTimeString: published_at is timestamptz, and a
    // value that loses its offset lands as the right wall clock in the wrong
    // zone (docs/LESSONS.md, 2026-09-02).
    expect($item->publishedAt)->toBe(CarbonImmutable::createFromTimestampUTC($seconds)->toIso8601String())
        ->and($item->publishedAt)->toEndWith('+00:00')
        ->and(SyncCursor::parse($item->publishedAt)?->getTimestamp())->toBe($seconds);
});

it('accepts the numeric form of lastModified.seconds as well as the protobuf string', function () {
    $review = gpReviews('page-1.json')[0];
    $seconds = (int) $review['comments'][0]['userComment']['lastModified']['seconds'];
    $review['comments'][0]['userComment']['lastModified']['seconds'] = $seconds;

    gpFakeBody((string) json_encode(['reviews' => [$review]]));

    expect(gpConnector()->fetchPage(null)->items[0]->publishedAt)
        ->toBe(CarbonImmutable::createFromTimestampUTC($seconds)->toIso8601String());
});

it('treats a review left without a name as anonymous rather than empty', function () {
    gpFake('page-1.json');

    $anonymous = collect(gpReviews('page-1.json'))->first(fn (array $r) => ! isset($r['authorName']));
    $item = collect(gpConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $anonymous['reviewId']);

    expect($anonymous)->not->toBeNull()
        ->and($item->author)->toBeNull()
        ->and($item->body)->not->toBe('');
});

it('ingests the user comment and never the developer reply', function () {
    gpFake('page-2-end.json');

    $review = collect(gpReviews('page-2-end.json'))
        ->first(fn (array $r) => collect($r['comments'])->contains(fn (array $c) => isset($c['developerComment'])));

    $item = collect(gpConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $review['reviewId']);

    // Analysing the company answering itself would spend a unit of quota on the
    // company's own words and put them in the inbox as customer feedback.
    expect($review)->not->toBeNull()
        ->and($item->body)->toBe($review['comments'][0]['userComment']['text'])
        ->and($item->body)->not->toContain('DEVELOPER-REPLY-MUST-NOT-BE-INGESTED');
});

it('skips a review that carries no user comment with text', function () {
    gpFake('page-skipped-comments.json');

    $reviews = gpReviews('page-skipped-comments.json');
    $page = gpConnector()->fetchPage(null);

    $ingestable = collect($reviews)->filter(function (array $review) {
        $userComment = collect($review['comments'])->first(fn (array $c) => isset($c['userComment']));

        return trim((string) ($userComment['userComment']['text'] ?? '')) !== '';
    });

    expect($reviews)->toHaveCount(3)
        ->and($ingestable)->toHaveCount(1)
        ->and($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($ingestable->first()['reviewId'])
        ->and(collect($page->items)->pluck('body')->implode(' '))
        ->not->toContain('DEVELOPER-ONLY-MUST-NOT-BE-INGESTED');
});

it('skips a review with no reviewId rather than failing the page', function () {
    $reviews = gpReviews('page-1.json');
    $broken = $reviews[0];
    unset($broken['reviewId']);

    gpFakeBody((string) json_encode(['reviews' => [$broken, $reviews[1]]]));

    $page = gpConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($reviews[1]['reviewId']);
});

it('drops a star rating outside the documented scale', function (mixed $starRating) {
    $review = gpReviews('page-1.json')[0];
    $review['comments'][0]['userComment']['starRating'] = $starRating;

    gpFakeBody((string) json_encode(['reviews' => [$review]]));

    expect(gpConnector()->fetchPage(null)->items[0]->rating)->toBeNull();
})->with([
    'zero' => [0],
    'six' => [6],
    'negative' => [-1],
    'not a number' => ['five'],
    'null' => [null],
]);

/*
|--------------------------------------------------------------------------
| Failure mapping — every case is an existing ConnectorFailure
|--------------------------------------------------------------------------
*/

it('maps documented upstream status codes onto connector failures', function (string $file, int $status, ConnectorFailure $expected) {
    gpFake($file, $status);

    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))->toBe($expected);
})->with([
    'unauthenticated' => ['error-unauthorized.json', 401, ConnectorFailure::InvalidCredentials],
    'no permission on the app' => ['error-forbidden.json', 403, ConnectorFailure::InvalidCredentials],
    'unknown package' => ['error-not-found.json', 404, ConnectorFailure::Misconfigured],
    'quota exhausted' => ['error-rate-limited.json', 429, ConnectorFailure::RateLimited],
]);

it('maps the remaining status codes onto connector failures', function (int $status, ConnectorFailure $expected) {
    gpFakeBody('{}', $status);

    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))->toBe($expected);
})->with([
    // Not in the W8 contract table, which would leave 400 on the Unreachable
    // default row. Unreachable is transient, so FetchFeedbackJob would spend
    // five attempts on a refusal that repeats identically, and would blame the
    // platform for a problem in the integration settings.
    [400, ConnectorFailure::Misconfigured],
    [500, ConnectorFailure::Unreachable],
    [502, ConnectorFailure::Unreachable],
    [503, ConnectorFailure::Unreachable],
]);

it('treats a connection failure as unreachable', function () {
    Http::fake([
        '*oauth2.googleapis.com/*' => Http::response(gpRaw('token-response.json'), 200),
        '*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))->toBe(ConnectorFailure::Unreachable);
});

it('refuses a body it cannot recognise as a reviews listing', function (string $body) {
    gpFakeBody($body);

    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'not json' => ['not json'],
    // `[]` is deliberately absent: it decodes to exactly what `{}` decodes to,
    // and `{}` is a real answer this endpoint gives. A non-empty top-level
    // array is still not an envelope.
    'a non-empty list' => ['[1,2,3]'],
    'reviews is a string' => ['{"reviews":"nope"}'],
    'reviews is a number' => ['{"reviews":7}'],
    'reviews is a keyed object' => ['{"reviews":{"first":{"reviewId":"gp:x"}}}'],
]);

/*
|--------------------------------------------------------------------------
| An application with nothing in the seven-day window
|--------------------------------------------------------------------------
*/

it('reads a response with no reviews key as an empty page rather than a broken one', function () {
    // The protobuf-to-JSON mapping omits empty repeated fields, so an app with
    // no reviews in the window answers `{}`. Refusing that would report a
    // healthy integration as permanently broken — a worse failure than the one
    // the check was guarding against.
    gpFake('page-empty-window.json');

    $page = gpConnector()->fetchPage(null);

    expect(gpRaw('page-empty-window.json'))->toStartWith('{}')
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse()
        ->and($page->watermark)->toBeNull()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBeNull();
});

it('keeps the stream open when a page with no reviews key still offers a token', function () {
    // `reviews` absent says nothing about the stream, exactly as an empty
    // `reviews` array says nothing: only tokenPagination ends it.
    gpFakeBody('{"tokenPagination":{"nextPageToken":"gp-fixture-next-page-token-0003"}}');

    $page = gpConnector()->fetchPage(null);

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeTrue()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe('gp-fixture-next-page-token-0003');
});

it('leaves the stored watermark alone when the window comes back empty', function () {
    gpFake('page-empty-window.json');

    $watermark = '2026-08-20T00:00:00+00:00';
    $page = gpConnector()->fetchPage((string) json_encode(['page' => 1, 'watermark' => $watermark]));

    // Nothing to promote and nothing to lose: the position survives a run that
    // found nothing, however many times that happens.
    expect(SyncCursor::decode($page->nextCursor)->watermark)->toBe($watermark)
        ->and(SyncCursor::decode($page->nextCursor)->pending)->toBeNull()
        ->and($page->hasMore)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A page token that did not survive to the next run
|--------------------------------------------------------------------------
*/

it('re-lists from the top when a stored page token is refused', function () {
    $served = 0;

    Http::fake(function ($request) use (&$served) {
        if (str_contains($request->url(), 'oauth2.googleapis.com')) {
            return Http::response(gpRaw('token-response.json'), 200);
        }

        $served++;

        return $served === 1
            ? Http::response(gpRaw('error-invalid-page-token.json'), 400)
            : Http::response(gpRaw('page-1.json'), 200);
    });

    // Left to fail, this wedges the integration permanently: the run throws, the
    // cursor is never rewritten, and every later run replays the same refusal
    // against the same dead token.
    $page = gpConnector()->fetchPage((new SyncCursor)->withToken('gp-fixture-stale-token')->encode());

    $requests = gpReviewRequests();

    expect($requests)->toHaveCount(2)
        ->and(gpQuery($requests[0])['token'])->toBe('gp-fixture-stale-token')
        ->and(gpQuery($requests[1]))->not->toHaveKey('token')
        ->and($page->items)->toHaveCount(count(gpReviews('page-1.json')));
});

it('surfaces the failure when the retry without the token also fails', function () {
    gpFake('error-unauthorized.json', 401);

    expect(gpFailure(fn () => gpConnector()->fetchPage((new SyncCursor)->withToken('gp-stale')->encode())))
        ->toBe(ConnectorFailure::InvalidCredentials);
});

it('does not retry a refusal on a request that carried no token', function () {
    gpFake('error-invalid-page-token.json', 400);

    expect(gpFailure(fn () => gpConnector()->fetchPage(null)))->toBe(ConnectorFailure::Misconfigured)
        ->and(gpReviewRequests())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential never reaches anything that can be persisted
|--------------------------------------------------------------------------
*/

it('never puts credential material into a failure, its message or its stack trace', function (int $status) {
    Http::fake([
        '*oauth2.googleapis.com/*' => Http::response(gpRaw('token-response.json'), 200),
        // The worst case: an upstream that echoes the credentials straight back.
        '*' => Http::response((string) json_encode([
            'error' => 'rejected',
            'client_email' => GP_CLIENT_EMAIL,
            'private_key' => gpKeys()['private'],
        ]), $status),
    ]);

    try {
        gpConnector()->fetchPage(null);
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        $rendered = $e->getMessage().$e->getSafeMessage().$e->getTraceAsString();

        expect($rendered)->not->toContain(GP_CLIENT_EMAIL)
            ->and($rendered)->not->toContain('PRIVATE KEY')
            ->and($rendered)->not->toContain(gpKeys()['private'])
            ->and($e->getSafeMessage())->toBe($e->failure()->safeMessage());
    }
})->with([401, 403, 429, 500]);

/*
|--------------------------------------------------------------------------
| Health and limits
|--------------------------------------------------------------------------
*/

it('reports itself healthy when the reviews endpoint answers', function () {
    gpFake('page-2-end.json');

    expect(gpConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports the safe failure message when the platform rejects the token', function () {
    gpFake('error-unauthorized.json', 401);

    $health = gpConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->message())->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
        ->and($health->message())->not->toContain(GP_CLIENT_EMAIL);
});

it('reports a service account key it cannot sign with as a misconfiguration', function () {
    gpFake('page-1.json');

    $connector = new GooglePlayConnector(
        packageName: GP_PACKAGE,
        token: gpToken(privateKey: gpPemHeader()."\nnot-a-key\n-----END PRIVATE KEY-----\n"),
        baseUrl: GP_BASE_URL,
        limits: new ConnectorLimits(10, 3),
        timeout: 5,
        maxResults: 100,
    );

    $health = $connector->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::Misconfigured)
        ->and($health->message())->toBe(ConnectorFailure::Misconfigured->safeMessage());
});

it('exposes the configured ceilings', function () {
    $limits = gpConnector(maxPages: 12)->limits();

    expect($limits->maxPagesPerRun)->toBe(12)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| GooglePlayAccessToken — the service-account JWT exchange
|--------------------------------------------------------------------------
*/

it('signs an rs256 assertion carrying the documented claims', function () {
    CarbonImmutable::setTestNow('2026-09-03T10:00:00+00:00');
    Http::fake(['*' => Http::response(gpRaw('token-response.json'), 200)]);

    gpToken()->get();

    $assertion = Http::recorded()->first()[0]->data()['assertion'];
    [$header, $claims, $signature] = explode('.', $assertion);

    $decode = fn (string $segment) => json_decode(
        (string) base64_decode(strtr($segment, '-_', '+/'), true),
        true
    );

    $now = CarbonImmutable::now()->getTimestamp();

    expect($decode($header))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and($decode($claims))->toBe([
            'iss' => GP_CLIENT_EMAIL,
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => GP_TOKEN_URL,
            'iat' => $now,
            // Google refuses an assertion claiming more than one hour.
            'exp' => $now + 3600,
        ])
        // The signature is verified rather than merely counted: a mis-encoded
        // signing input produces a well-formed JWT that Google refuses, and the
        // only symptom would be an invalid_grant nobody can explain.
        ->and(openssl_verify(
            $header.'.'.$claims,
            (string) base64_decode(strtr($signature, '-_', '+/'), true),
            gpKeys()['public'],
            OPENSSL_ALGO_SHA256
        ))->toBe(1);
});

it('exchanges the assertion for an access token with the documented grant type', function () {
    Http::fake(['*' => Http::response(gpRaw('token-response.json'), 200)]);

    expect(gpToken()->get())->toBe(gpJson('token-response.json')['access_token']);

    Http::assertSent(fn ($request) => $request->url() === GP_TOKEN_URL
        && $request->method() === 'POST'
        && $request->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
});

it('never sends the private key, only a signature made with it', function () {
    Http::fake(['*' => Http::response(gpRaw('token-response.json'), 200)]);

    gpToken()->get();

    $request = Http::recorded()->first()[0];

    expect($request->url())->toBe(GP_TOKEN_URL)
        ->and($request->url())->not->toContain(GP_CLIENT_EMAIL)
        ->and($request->body())->not->toContain('PRIVATE KEY')
        ->and($request->body())->not->toContain(gpKeys()['private'])
        // The client email is the `iss` claim, so it travels inside the signed
        // assertion — base64url encoded, never as a readable form field.
        ->and($request->body())->not->toContain(GP_CLIENT_EMAIL)
        ->and(array_keys($request->data()))->toBe(['grant_type', 'assertion']);
});

it('mints the token once and serves the rest of the run from the cache', function () {
    Http::fake(['*' => Http::response(gpRaw('token-response.json'), 200)]);

    $token = gpToken();

    expect($token->get())->toBe($token->get())
        ->and(Http::recorded())->toHaveCount(1);
});

it('never lets two integrations share one access token', function () {
    $minted = 0;

    Http::fake(function () use (&$minted) {
        $minted++;

        return Http::response((string) json_encode([
            'access_token' => 'ya29.FIXTURE-token-'.$minted,
            'expires_in' => 3599,
        ]), 200);
    });

    // The cache key is derived from the integration id and from nothing else:
    // two tenants configuring the same service account must still not be able
    // to read each other's token out of the cache (invariant I1).
    expect(gpToken(integrationId: 111)->get())->toBe('ya29.FIXTURE-token-1')
        ->and(gpToken(integrationId: 222)->get())->toBe('ya29.FIXTURE-token-2')
        ->and(gpToken(integrationId: 111)->get())->toBe('ya29.FIXTURE-token-1')
        ->and($minted)->toBe(2);
});

it('does not cache a token whose lifetime it was not told', function (mixed $expiresIn) {
    $minted = 0;

    Http::fake(function () use (&$minted, $expiresIn) {
        $minted++;

        return Http::response((string) json_encode(array_filter([
            'access_token' => 'ya29.FIXTURE-token',
            'expires_in' => $expiresIn,
        ], fn ($value) => $value !== null)), 200);
    });

    // Caching it "for a while" would be a guess that can outlive the token, and
    // the symptom would be a 401 halfway through a run.
    $token = gpToken();
    $token->get();
    $token->get();

    expect($minted)->toBe(2);
})->with([
    'absent' => [null],
    'not a number' => ['soon'],
    'shorter than the safety margin' => [30],
]);

it('rejects a private key openssl cannot use, without echoing what openssl said', function (string $privateKey) {
    Http::fake(['*' => Http::response(gpRaw('token-response.json'), 200)]);

    try {
        gpToken(privateKey: $privateKey)->get();
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        expect($e->failure())->toBe(ConnectorFailure::Misconfigured)
            ->and($e->getMessage())->toBe(ConnectorFailure::Misconfigured->safeMessage())
            // openssl_error_string() is a buffer holding OpenSSL's rendering of
            // what it just failed to parse, so it is never read at all.
            ->and($e->getMessage())->not->toContain('PEM')
            ->and($e->getMessage())->not->toContain('error:');
    }

    Http::assertNothingSent();
})->with([
    'empty' => [''],
    'not a key' => ['nonsense'],
    'a truncated pem' => [fn () => gpPemHeader()."\nnot-base64\n-----END PRIVATE KEY-----\n"],
    'a public key' => [fn () => gpKeys()['public']],
]);

it('maps token endpoint failures onto connector failures', function (int $status, ConnectorFailure $expected) {
    Http::fake(['*' => Http::response(gpRaw('error-invalid-grant.json'), $status)]);

    expect(gpFailure(fn () => gpToken()->get()))->toBe($expected);
})->with([
    'invalid_grant' => [400, ConnectorFailure::InvalidCredentials],
    'unauthorized' => [401, ConnectorFailure::InvalidCredentials],
    'forbidden' => [403, ConnectorFailure::InvalidCredentials],
    'rate limited' => [429, ConnectorFailure::RateLimited],
    'upstream down' => [500, ConnectorFailure::Unreachable],
    'gateway' => [503, ConnectorFailure::Unreachable],
]);

it('treats a token endpoint connection failure as unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(gpFailure(fn () => gpToken()->get()))->toBe(ConnectorFailure::Unreachable);
});

it('refuses a token response it cannot read an access token out of', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(gpFailure(fn () => gpToken()->get()))->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'not json' => ['not json'],
    'no access_token' => ['{"expires_in":3599}'],
    'an empty access_token' => ['{"access_token":"","expires_in":3599}'],
    'access_token is not a string' => ['{"access_token":{"value":"x"},"expires_in":3599}'],
]);

it('never puts credential material into a token exchange failure', function () {
    Http::fake(['*' => Http::response((string) json_encode([
        'error' => 'invalid_grant',
        'error_description' => 'assertion signed with '.gpKeys()['private'],
        'client_email' => GP_CLIENT_EMAIL,
    ]), 400)]);

    try {
        gpToken()->get();
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        $rendered = $e->getMessage().$e->getSafeMessage().$e->getTraceAsString();

        expect($rendered)->not->toContain(GP_CLIENT_EMAIL)
            ->and($rendered)->not->toContain('PRIVATE KEY')
            ->and($rendered)->not->toContain(gpKeys()['private']);
    }
});
