<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\MastodonConnector;
use App\Support\Connectors\SyncCursor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Mastodon hashtag timeline — the social channel
|--------------------------------------------------------------------------
|
| The fixtures here were **recorded live** from mastodon.social on 2026-09-04
| and then redacted: the envelope is the recorded one, the identities and the
| post text are written for this repository.
| contracts/fixtures/platforms/social/README.md says field by field what was
| recorded and what is inferred, and the last section of this file asserts the
| redaction rather than restating it.
|
| Expectations are derived from the fixture at run time, for the same reason
| they are in the App Store, Zendesk and Trustpilot suites: the content is
| replaceable, the shape is what has to hold.
|
| The connector under test is built with `limit: 5`, so page-1.json (five
| statuses) is a *full* page and page-2-last.json (three) is a short one. That
| relation is the end-of-feed signal, so it is load bearing and asserted below.
|
*/

const MD_INSTANCE = 'https://social.example.invalid';
const MD_HASHTAG = 'omnihear';
const MD_LIMIT = 5;

function mastodonConnector(int $maxPages = 20, int $limit = MD_LIMIT, string $hashtag = MD_HASHTAG): MastodonConnector
{
    return new MastodonConnector(
        instanceUrl: MD_INSTANCE,
        hashtag: $hashtag,
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        limit: $limit,
    );
}

function mastodonFake(string $file, int $status = 200): void
{
    Http::fake(['*' => Http::response(PlatformFixture::raw('social', $file), $status)]);
}

/**
 * @return list<array<string, mixed>>
 */
function mastodonStatuses(string $file): array
{
    /** @var list<array<string, mixed>> $statuses */
    $statuses = json_decode(PlatformFixture::raw('social', $file), true, 64, JSON_THROW_ON_ERROR);

    return $statuses;
}

/**
 * Serve one hand-made list of statuses. Used only to mutate a *fixture* status
 * — never to invent a shape a fixture owns.
 *
 * @param  list<array<string, mixed>>  $statuses
 */
function mastodonServeStatuses(array $statuses): void
{
    Http::fake(['*' => Http::response((string) json_encode($statuses), 200)]);
}

/**
 * The query string of the request that was sent, decoded.
 *
 * @return array<string, string>
 */
function mastodonQuery(object $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * Headers keyed lower-case; header names are case-insensitive on the wire and
 * this test must not pass or fail on the casing the client happens to use.
 *
 * @return array<string, list<string>>
 */
function mastodonHeaders(object $request): array
{
    $headers = [];

    /** @var array<string, list<string>> $raw */
    $raw = $request->headers();

    foreach ($raw as $name => $values) {
        $headers[strtolower((string) $name)] = $values;
    }

    return $headers;
}

function mastodonFailure(callable $call): ConnectorFailure
{
    try {
        $call();
    } catch (ConnectorException $e) {
        return $e->failure();
    }

    throw new RuntimeException('Expected a ConnectorException, none was thrown.');
}

/** The token a cursor carries, or null. */
function mastodonToken(?string $cursor): ?string
{
    return SyncCursor::decode($cursor)->token;
}

/*
|--------------------------------------------------------------------------
| The request — no credential at all, and the cold start versus a later run
|--------------------------------------------------------------------------
*/

it('asks the public hashtag timeline of the configured instance', function () {
    mastodonFake('page-1.json');

    mastodonConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => str_starts_with(
        $request->url(),
        MD_INSTANCE.'/api/v1/timelines/tag/'.MD_HASHTAG.'?'
    ));
});

it('sends no authorization of any kind, because this channel has no credential', function () {
    // The whole reason this channel is Mastodon: the public timeline answers
    // 200 without an account. There is nothing to leak, and nothing may be
    // invented to send.
    mastodonFake('page-1.json');

    mastodonConnector()->fetchPage('{"token":"117900000000000891"}');

    Http::assertSent(function ($request) {
        $headers = mastodonHeaders($request);

        return ! isset($headers['authorization'])
            && ! array_key_exists('access_token', mastodonQuery($request))
            && ! array_key_exists('apikey', mastodonQuery($request))
            && $request->body() === '';
    });
});

it('reads the newest page and nothing behind it on the first run', function () {
    // No lookback window: the page size is the bound. A first sync that walked
    // a busy hashtag back through its history would spend the whole analysis
    // quota before the tenant saw anything.
    mastodonFake('page-1.json');

    mastodonConnector()->fetchPage(null);

    Http::assertSent(function ($request) {
        $query = mastodonQuery($request);

        return $query['limit'] === (string) MD_LIMIT
            && ! array_key_exists('min_id', $query)
            && ! array_key_exists('max_id', $query)
            && ! array_key_exists('since_id', $query);
    });
});

it('walks forward from the stored token on a later run', function () {
    mastodonFake('page-1.json');

    mastodonConnector()->fetchPage('{"token":"117800000000000001"}');

    Http::assertSent(fn ($request) => mastodonQuery($request)['min_id'] === '117800000000000001'
        && mastodonQuery($request)['limit'] === (string) MD_LIMIT);
});

it('treats an empty stored token as no token at all', function () {
    mastodonFake('page-1.json');

    mastodonConnector()->fetchPage('{"token":""}');

    Http::assertSent(fn ($request) => ! array_key_exists('min_id', mastodonQuery($request)));
});

it('clamps the page size to the documented ceiling instead of sending it', function () {
    mastodonFake('page-1.json');

    mastodonConnector(limit: 500)->fetchPage(null);

    Http::assertSent(fn ($request) => mastodonQuery($request)['limit'] === '40');
});

/*
|--------------------------------------------------------------------------
| The hashtag reaches the URL path, so it is whitelisted
|--------------------------------------------------------------------------
*/

it('refuses a hashtag that is not letters, digits or underscore', function (string $hashtag) {
    // The value goes into the URL path. A value carrying `/`, `?` or `..` would
    // point the request at an endpoint of the writer's choosing — the same
    // reasoning as Trustpilot's 24-hex id and Google Play's package name.
    expect(mastodonFailure(fn () => mastodonConnector(hashtag: $hashtag)))
        ->toBe(ConnectorFailure::Misconfigured);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'path traversal' => ['../../v2/admin'],
    'slash' => ['omni/hear'],
    'query injection' => ['omnihear?limit=99'],
    'absolute url' => ['https://evil.test/x'],
    'leading hash' => ['#omnihear'],
    'space' => ['omni hear'],
    'dot' => ['omni.hear'],
    'too long' => [str_repeat('a', 101)],
]);

it('accepts a hashtag written in a language that is not english', function () {
    // A Turkish hashtag is a hashtag. The whitelist is unicode-aware for
    // exactly this reason, and the product's first market is Turkish.
    mastodonFake('page-1.json');

    mastodonConnector(hashtag: 'müşterigeribildirimi')->fetchPage(null);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        '/api/v1/timelines/tag/'.rawurlencode('müşterigeribildirimi').'?'
    ));
});

it('refuses an instance url that is not https', function (string $url) {
    // Every request goes wherever this points, so the scheme is whitelisted
    // rather than trusted — the same rule EmailConnector applies to session_url.
    $build = fn () => new MastodonConnector(
        instanceUrl: $url,
        hashtag: MD_HASHTAG,
        limits: new ConnectorLimits(20, 3),
        timeout: 5,
        limit: MD_LIMIT,
    );

    expect(mastodonFailure($build))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'plain http' => ['http://social.example.invalid'],
    'no scheme' => ['social.example.invalid'],
    'file' => ['file:///etc/passwd'],
    'empty' => [''],
    'not a url' => ['not a url at all'],
]);

/*
|--------------------------------------------------------------------------
| Field mapping
|--------------------------------------------------------------------------
*/

it('maps a status onto a connector item', function () {
    mastodonFake('page-1.json');

    $status = mastodonStatuses('page-1.json')[0];
    $item = collect(mastodonConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $status['id']);

    expect($item)->not->toBeNull()
        ->and($item->author)->toBe($status['account']['display_name'])
        ->and($item->sourceUrl)->toBe($status['url'])
        // created_at, offset preserved. The runner stores it with
        // toIso8601String(); toDateTimeString() drops the offset and has
        // already put a row seven hours off once.
        ->and($item->publishedAt)->toBe($status['created_at'])
        ->and($item->rawPayload)->toBe($status);
});

it('never gives a status a rating', function () {
    // Favourites and boosts are popularity, not sentiment. Projecting them onto
    // the 1-5 scale would invent a number the platform never expressed.
    mastodonFake('page-1.json');

    foreach (mastodonConnector()->fetchPage(null)->items as $item) {
        expect($item->rating)->toBeNull();
    }
});

it('takes the author from the display name and never from acct', function () {
    // For a remote account `acct` is `user@domain`, and IngestionRunner::maskPii
    // rewrites anything address-shaped to `[email]`. Passing acct through would
    // not merely be untidy: it would throw the name away and put the literal
    // string `[email]` in the inbox where a person's name belongs.
    mastodonFake('page-1.json');

    $status = collect(mastodonStatuses('page-1.json'))
        ->first(fn (array $s) => str_contains((string) $s['account']['acct'], '@'));

    $item = collect(mastodonConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $status['id']);

    expect($status['account']['acct'])->toContain('@')
        ->and($item->author)->toBe($status['account']['display_name'])
        ->and($item->author)->not->toBe($status['account']['acct'])
        ->and($item->author)->not->toContain('@')
        ->and($item->author)->not->toContain('[email]');
});

it('falls back to the username when the account left its display name blank', function () {
    mastodonFake('page-1.json');

    $status = collect(mastodonStatuses('page-1.json'))
        ->first(fn (array $s) => $s['account']['display_name'] === '');

    $item = collect(mastodonConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $status['id']);

    expect($status['account']['display_name'])->toBe('')
        ->and($item->author)->toBe($status['account']['username'])
        ->and($item->author)->not->toBe('');
});

it('tolerates a status with no account block', function () {
    $status = mastodonStatuses('page-1.json')[0];
    unset($status['account']);

    mastodonServeStatuses([$status]);

    expect(mastodonConnector()->fetchPage(null)->items[0]->author)->toBeNull();
});

it('turns the sanitised html of a status into the plain text the analyzer reads', function () {
    mastodonFake('page-1.json');

    // The status the fixture carries with <br> line breaks and HTML entities.
    $status = collect(mastodonStatuses('page-1.json'))
        ->first(fn (array $s) => str_contains((string) $s['content'], '<br>')
            && str_contains((string) $s['content'], '&amp;'));

    $item = collect(mastodonConnector()->fetchPage(null)->items)
        ->firstWhere('externalId', $status['id']);

    expect($item->body)
        // The markup is gone, all of it.
        ->not->toContain('<')
        ->not->toContain('>')
        // Deliberate line breaks are structure the poster wrote; they survive.
        ->toContain("\n")
        // Entities are decoded after the tags are removed, so an escaped
        // angle bracket in the original text cannot re-enter as a tag.
        ->toContain('&')
        ->not->toContain('&amp;')
        ->not->toContain('&#39;')
        // The hashtag anchor collapses to its text, `#` included.
        ->toContain('#'.MD_HASHTAG)
        ->and(trim($item->body))->toBe($item->body);
});

it('turns a paragraph break into a blank line', function () {
    $status = mastodonStatuses('page-1.json')[0];
    $status['content'] = '<p>Birinci paragraf.</p><p>Ikinci paragraf.</p>';

    mastodonServeStatuses([$status]);

    expect(mastodonConnector()->fetchPage(null)->items[0]->body)
        ->toBe("Birinci paragraf.\n\nIkinci paragraf.");
});

it('uses the federated uri when the status has no local permalink', function () {
    mastodonFake('page-2-last.json');

    $status = collect(mastodonStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['url'] === null);

    $item = collect(mastodonConnector()->fetchPage('{"token":"117900000000000771"}')->items)
        ->firstWhere('externalId', $status['id']);

    expect($status['url'])->toBeNull()
        ->and($status['uri'])->not->toBeNull()
        ->and($item->sourceUrl)->toBe($status['uri']);
});

/*
|--------------------------------------------------------------------------
| What is not feedback
|--------------------------------------------------------------------------
*/

it('skips a boost, because the words in it are not the booster', function () {
    mastodonFake('page-2-last.json');

    $boost = collect(mastodonStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['reblog'] !== null);

    $page = mastodonConnector()->fetchPage('{"token":"117900000000000771"}');

    expect($boost)->not->toBeNull()
        ->and($boost['reblog']['id'])->not->toBe($boost['id'])
        ->and(collect($page->items)->pluck('externalId')->all())->not->toContain($boost['id'])
        // The nested original is not ingested under the wrapper's id either;
        // it will arrive on its own if it carries the hashtag.
        ->and(collect($page->items)->pluck('externalId')->all())->not->toContain($boost['reblog']['id']);
});

it('skips a boost even when the wrapper carries text of its own', function () {
    // Mastodon leaves a reblog wrapper's own content empty, so the fixture does
    // too. `reblog !== null` — not an empty body — has to be what skips it,
    // otherwise a server that fills the wrapper in would slip through.
    $boost = collect(mastodonStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['reblog'] !== null);
    $boost['content'] = '<p>Bunu herkes gormeli.</p>';

    mastodonServeStatuses([$boost]);

    expect(mastodonConnector()->fetchPage(null)->items)->toBeEmpty();
});

it('skips a status whose markup strips to nothing at all', function () {
    // A media-only post. Ingesting it would put a blank row in the inbox and
    // spend a unit of analysis quota on it.
    mastodonFake('page-2-last.json');

    // Not the boost: a reblog wrapper also carries an empty content, and this
    // test is about the stripping rule rather than the reblog rule.
    $empty = collect(mastodonStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['reblog'] === null
            && trim(strip_tags((string) $s['content'])) === '');

    $page = mastodonConnector()->fetchPage('{"token":"117900000000000771"}');

    expect($empty)->not->toBeNull()
        ->and($empty['reblog'])->toBeNull()
        ->and(collect($page->items)->pluck('externalId')->all())->not->toContain($empty['id'])
        ->and($page->items)->toHaveCount(count(mastodonStatuses('page-2-last.json')) - 2);
});

it('skips a status whose content is not a string rather than failing the page', function (mixed $content) {
    $statuses = mastodonStatuses('page-1.json');
    $statuses[0]['content'] = $content;

    mastodonServeStatuses([$statuses[0], $statuses[1]]);

    $page = mastodonConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($statuses[1]['id']);
})->with([
    'null' => [null],
    'absent-shaped' => [''],
    'a number' => [7],
    'a list' => [[]],
]);

it('skips a status with no id rather than failing the page', function () {
    $statuses = mastodonStatuses('page-1.json');
    $broken = $statuses[0];
    unset($broken['id']);

    mastodonServeStatuses([$broken, $statuses[1]]);

    $page = mastodonConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe($statuses[1]['id']);
});

it('carries every ingestable status of the recorded page, in order', function () {
    mastodonFake('page-1.json');

    $expected = array_map(
        static fn (array $status): string => (string) $status['id'],
        mastodonStatuses('page-1.json'),
    );

    $page = mastodonConnector()->fetchPage(null);

    expect(array_map(fn ($item) => $item->externalId, $page->items))->toBe($expected)
        ->and(array_unique($expected))->toHaveCount(count($expected));
});

/*
|--------------------------------------------------------------------------
| Paging — the cold start stops, a full page continues, a short page ends
|--------------------------------------------------------------------------
*/

it('stops the first run after the newest page even when that page is full', function () {
    mastodonFake('page-1.json');

    $page = mastodonConnector()->fetchPage(null);

    expect(mastodonStatuses('page-1.json'))->toHaveCount(MD_LIMIT)
        ->and($page->hasMore)->toBeFalse()
        ->and(mastodonToken($page->nextCursor))->toBe(mastodonStatuses('page-1.json')[0]['id']);
});

it('continues past a full page on a later run', function () {
    mastodonFake('page-1.json');

    $page = mastodonConnector()->fetchPage('{"token":"117800000000000001"}');

    expect($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->not->toBeNull()
        // The largest id on a newest-first page is its first element's. Ids are
        // opaque strings by Mastodon's own guidance, so nothing compares them
        // numerically; "largest" is read off the ordering the API guarantees.
        ->and(mastodonToken($page->nextCursor))->toBe(mastodonStatuses('page-1.json')[0]['id']);
});

it('ends the run on a short page', function () {
    mastodonFake('page-2-last.json');

    $page = mastodonConnector()->fetchPage('{"token":"117800000000000001"}');

    expect(count(mastodonStatuses('page-2-last.json')))->toBeLessThan(MD_LIMIT)
        ->and($page->hasMore)->toBeFalse()
        ->and(mastodonToken($page->nextCursor))->toBe(mastodonStatuses('page-2-last.json')[0]['id']);
});

it('counts the statuses the server sent, not the ones that survived mapping', function () {
    // A full page of boosts maps to zero items. Reading that as a short page
    // would end a run that had only just started and bury everything behind it
    // — ConnectorPage's own rule 1: items === [] says nothing about the stream.
    $boost = collect(mastodonStatuses('page-2-last.json'))
        ->first(fn (array $s) => $s['reblog'] !== null);

    $statuses = [];

    foreach (range(1, MD_LIMIT) as $i) {
        $copy = $boost;
        $copy['id'] = '11790000000000100'.$i;
        $statuses[] = $copy;
    }

    mastodonServeStatuses(array_reverse($statuses));

    $page = mastodonConnector()->fetchPage('{"token":"117800000000000001"}');

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeTrue();
});

it('keeps the stored token when a page comes back empty', function () {
    // Replacing it with null would restart the next run from the newest page
    // and re-ingest everything. I2 would absorb the rows; the analysis quota
    // would not absorb the work.
    mastodonFake('page-empty.json');

    $page = mastodonConnector()->fetchPage('{"token":"117900000000000891"}');

    expect(mastodonStatuses('page-empty.json'))->toBeEmpty()
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse()
        ->and($page->watermark)->toBeNull()
        ->and(mastodonToken($page->nextCursor))->toBe('117900000000000891');
});

it('never reports the stream ended just because it reached the runners page cap', function () {
    // A connector that answered hasMore=false on reaching maxPagesPerRun would
    // be telling IngestionRunner the run *completed*, letting it persist a
    // position it never reached (docs/LESSONS.md, capped-run entry). The cap is
    // the runner's runaway-loop ceiling and stays the runner's to apply.
    mastodonFake('page-1.json');

    $page = mastodonConnector(maxPages: 1)->fetchPage('{"token":"117800000000000001"}');

    expect($page->hasMore)->toBeTrue()
        ->and(mastodonToken($page->nextCursor))->toBe(mastodonStatuses('page-1.json')[0]['id']);
});

it('publishes the newest created_at on the page as the watermark', function () {
    mastodonFake('page-1.json');

    $newest = max(array_map(
        fn (array $status) => SyncCursor::parse($status['created_at'])?->getTimestamp(),
        mastodonStatuses('page-1.json'),
    ));

    $page = mastodonConnector()->fetchPage(null);

    expect(SyncCursor::parse($page->watermark)?->getTimestamp())->toBe($newest);
});

it('keeps the encoded cursor inside the varchar(255) column', function () {
    mastodonFake('page-1.json');

    $page = mastodonConnector()->fetchPage(null);

    expect(strlen((string) $page->nextCursor))->toBeLessThan(255)
        // The token is the whole position, so nothing else is encoded next to
        // it — the Zendesk shape, and what makes the fit certain.
        ->and(json_decode((string) $page->nextCursor, true))->toBe([
            'page' => 1,
            'token' => mastodonStatuses('page-1.json')[0]['id'],
        ]);
});

/*
|--------------------------------------------------------------------------
| Failure mapping
|--------------------------------------------------------------------------
*/

it('maps the recorded error bodies onto connector failures', function (string $file, int $status, ConnectorFailure $expected) {
    mastodonFake($file, $status);

    expect(mastodonFailure(fn () => mastodonConnector()->fetchPage(null)))->toBe($expected);
})->with([
    // Recorded: the instance answers this when the endpoint needs a token,
    // which for a tag timeline means public preview is switched off.
    'public preview disabled' => ['error-unauthorized.json', 401, ConnectorFailure::InvalidCredentials],
    // Recorded, and not JSON at all — an HTML page. The status is the signal;
    // nothing is read out of the body.
    'not a mastodon endpoint' => ['error-not-found.html', 404, ConnectorFailure::Misconfigured],
    'request refused' => ['error-unprocessable.json', 422, ConnectorFailure::Misconfigured],
    'throttled' => ['error-rate-limited.json', 429, ConnectorFailure::RateLimited],
    // Not recorded live — no suspended or defederated instance was found to
    // capture — but 403 is a standing decision by the server, not a blip.
    // Unreachable is retryable by design and would burn five attempts on a
    // refusal that reads identically on the sixth, so this is Misconfigured
    // rather than falling into the default arm.
    'suspended or defederated instance' => ['error-forbidden.json', 403, ConnectorFailure::Misconfigured],
]);

it('maps the remaining status codes onto connector failures', function (int $status, ConnectorFailure $expected) {
    Http::fake(['*' => Http::response('[]', $status)]);

    expect(mastodonFailure(fn () => mastodonConnector()->fetchPage(null)))->toBe($expected);
})->with([
    [400, ConnectorFailure::Unreachable],
    [500, ConnectorFailure::Unreachable],
    [502, ConnectorFailure::Unreachable],
    [503, ConnectorFailure::Unreachable],
]);

it('treats a connection failure as unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(mastodonFailure(fn () => mastodonConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::Unreachable);
});

it('refuses a body it cannot recognise as a timeline', function (string $body) {
    // Every error this endpoint returns is a JSON *object*. Accepting one would
    // turn a refusal into a silent "no new feedback", which is worse than
    // failing the run.
    Http::fake(['*' => Http::response($body, 200)]);

    expect(mastodonFailure(fn () => mastodonConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'not json' => ['not json'],
    'an html page' => ['<html>maintenance</html>'],
    'an error object' => ['{"error":"Record not found"}'],
    // `{}` and `[]` decode to the same PHP value, so the raw body is what
    // separates them.
    'an empty object' => ['{}'],
    'a bare string' => ['"nope"'],
    'a number' => ['7'],
]);

it('drops a non-object entry rather than failing the page', function () {
    $statuses = mastodonStatuses('page-1.json');

    Http::fake(['*' => Http::response(
        (string) json_encode([$statuses[0], 'nonsense', 42, $statuses[1]]),
        200
    )]);

    expect(mastodonConnector()->fetchPage(null)->items)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — no credential exists, and nothing thrown is built from a body
|--------------------------------------------------------------------------
*/

it('never builds a failure, its message or its trace from the response body', function (int $status) {
    // There is no credential on this channel, so the half of I5 that survives
    // is the structural half: the sentence the user sees is chosen from a closed
    // set, never assembled from what the platform said.
    Http::fake(['*' => Http::response(
        (string) json_encode(['error' => 'UPSTREAM-ECHO-BODY', 'secret' => 'super-secret-value']),
        $status
    )]);

    try {
        mastodonConnector()->fetchPage(null);
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        expect($e->getMessage())->not->toContain('UPSTREAM-ECHO-BODY')
            ->and($e->getMessage())->not->toContain('super-secret-value')
            ->and($e->getSafeMessage())->not->toContain('UPSTREAM-ECHO-BODY')
            ->and($e->getTraceAsString())->not->toContain('super-secret-value')
            ->and($e->getSafeMessage())->toBe($e->failure()->safeMessage());
    }
})->with([401, 403, 404, 422, 429, 500]);

/*
|--------------------------------------------------------------------------
| Health and limits
|--------------------------------------------------------------------------
*/

it('reports itself healthy when the timeline answers', function () {
    mastodonFake('page-1.json');

    expect(mastodonConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports itself healthy on a hashtag nobody has used yet', function () {
    // An unused hashtag answers an empty list, and that is a correctly
    // configured integration, not a fault.
    mastodonFake('page-empty.json');

    expect(mastodonConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports the safe failure message when the instance refuses public preview', function () {
    mastodonFake('error-unauthorized.json', 401);

    $health = mastodonConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::InvalidCredentials)
        ->and($health->message())->toBe(ConnectorFailure::InvalidCredentials->safeMessage());
});

it('reports unhealthy when the host is not a mastodon-compatible server', function () {
    mastodonFake('error-not-found.html', 404);

    $health = mastodonConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::Misconfigured)
        ->and($health->message())->not->toContain('Mastodon');
});

it('checks health without a cursor, so a healthy integration is never advanced by the check', function () {
    mastodonFake('page-1.json');

    mastodonConnector()->healthCheck();

    Http::assertSent(fn ($request) => ! array_key_exists('min_id', mastodonQuery($request)));
});

it('exposes the configured ceilings', function () {
    $limits = mastodonConnector(maxPages: 20)->limits();

    expect($limits->maxPagesPerRun)->toBe(20)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The fixtures themselves — decision D-06
|--------------------------------------------------------------------------
|
| These are the first fixtures since App Store recorded from a live service, so
| the redaction is the whole safety margin. The provenance README promises no
| real person, host or instance survives in them; this asserts the promise
| rather than restating it, so a re-recording cannot quietly bring one in.
|
| The raw capture never entered the working tree — it was written outside the
| repository, redacted there and audited against the capture before anything was
| copied in. This repository has already paid once, with a full history rewrite,
| for skipping that.
|
*/

/**
 * @return list<string>
 */
function mastodonFixtureFiles(): array
{
    $directory = dirname(PlatformFixture::path('social', 'page-1.json'));

    $files = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $name): bool => is_file($directory.DIRECTORY_SEPARATOR.$name),
    ));

    sort($files);

    return $files;
}

it('holds no real person, host or instance in any fixture', function () {
    foreach (mastodonFixtureFiles() as $file) {
        $raw = PlatformFixture::raw('social', $file);

        preg_match_all('#https?://([^/"\s]+)#', $raw, $hosts);

        foreach ($hosts[1] as $host) {
            // RFC 2606 reserves .invalid, so nothing here can resolve to a
            // real instance, a real avatar or a real person's profile.
            expect($host)->toEndWith('example.invalid');
        }

        preg_match_all('/[\w.+-]+@[\w.-]+/', $raw, $addresses);

        foreach ($addresses[0] as $address) {
            expect($address)->toEndWith('example.invalid');
        }

        expect(strtolower($raw))->not->toContain('mastodon.social')
            ->and(strtolower($raw))->not->toContain('coffee');
    }
});

it('keeps every recorded identity synthetic', function (string $file) {
    $check = function (array $status) use (&$check, $file) {
        $account = $status['account'];

        expect($account['display_name'] === '' || preg_match('/^poster-\d{2}$/', (string) $account['display_name']) === 1)
            ->toBeTrue("Fixture {$file} carries a display name that is not synthetic.")
            ->and($account['username'])->toMatch('/^poster-\d{2}$/')
            ->and($account['acct'])->toMatch('/^poster-\d{2}(@[a-z0-9.]+\.example\.invalid)?$/')
            ->and($account['id'])->toMatch('/^\d{18}$/');

        foreach ($status['mentions'] as $mention) {
            expect($mention['username'])->toMatch('/^poster-\d{2}$/')
                ->and($mention['acct'])->toEndWith('example.invalid');
        }

        if ($status['reblog'] !== null) {
            $check($status['reblog']);
        }
    };

    foreach (mastodonStatuses($file) as $status) {
        $check($status);
    }
})->with(['page-1.json', 'page-2-last.json']);

it('keeps the recorded envelope on every status', function (string $file) {
    $core = [
        'id', 'created_at', 'in_reply_to_id', 'in_reply_to_account_id', 'sensitive',
        'spoiler_text', 'visibility', 'language', 'uri', 'url', 'replies_count',
        'reblogs_count', 'favourites_count', 'quotes_count', 'edited_at', 'content',
        'reblog', 'account', 'media_attachments', 'mentions', 'tags', 'emojis',
        'tagged_collections', 'quote', 'card', 'poll', 'quote_approval',
    ];

    foreach (mastodonStatuses($file) as $status) {
        // `application` is the one irregular key: the recorded response carries
        // it on statuses local to the queried instance and omits it on
        // federated ones.
        $extra = array_values(array_diff(array_keys($status), $core));

        expect(array_values(array_intersect(array_keys($status), $core)))->toBe($core)
            ->and($extra === [] || $extra === ['application'])->toBeTrue()
            // 18 digits, all numeric — the recorded id format, and what proves
            // the encoded cursor fits sync_cursor varchar(255).
            ->and($status['id'])->toMatch('/^\d{18}$/')
            // Millisecond precision and a Z suffix, as recorded. The runner
            // stores published_at with toIso8601String() so the offset survives
            // into timestamptz.
            ->and($status['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
            ->and($status['visibility'])->toBe('public');
    }
})->with(['page-1.json', 'page-2-last.json']);

it('keeps each recorded page newest-first, with page two ahead of page one', function () {
    $ids = fn (string $file) => array_map(
        static fn (array $status): string => (string) $status['id'],
        mastodonStatuses($file),
    );

    $timestamps = fn (string $file) => array_map(
        fn (array $status) => SyncCursor::parse($status['created_at'])?->getTimestamp(),
        mastodonStatuses($file),
    );

    $descending = function (array $values): bool {
        $sorted = $values;
        rsort($sorted);

        return $values === $sorted;
    };

    $first = $ids('page-1.json');
    $second = $ids('page-2-last.json');

    // `min_id` walks *forward*: a run fetches the page nearest its stored
    // token first, and the page behind it is newer. So page two is ahead of
    // page one here, the opposite of the max_id-based App Store and Trustpilot
    // fixtures — and the ingestion test reads the two as one forward stream, so
    // the relation is load bearing.
    expect($descending($first))->toBeTrue()
        ->and($descending($second))->toBeTrue()
        ->and(min($second))->toBeGreaterThan(max($first))
        ->and($descending($timestamps('page-1.json')))->toBeTrue()
        ->and($descending($timestamps('page-2-last.json')))->toBeTrue();
});

it('keeps page one full and page two short against the page size these tests use', function () {
    // The connector's end-of-feed signal is count(statuses) < limit, so these
    // two counts are what make "continues" and "ends" mean anything above.
    expect(mastodonStatuses('page-1.json'))->toHaveCount(MD_LIMIT)
        ->and(count(mastodonStatuses('page-2-last.json')))->toBeLessThan(MD_LIMIT)
        ->and(mastodonStatuses('page-empty.json'))->toBeEmpty();
});

it('keeps the fixture set small enough to read', function () {
    expect(count(mastodonFixtureFiles()))->toBeLessThanOrEqual(8);
});
