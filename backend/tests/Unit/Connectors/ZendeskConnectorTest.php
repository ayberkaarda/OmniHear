<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\SyncCursor;
use App\Support\Connectors\ZendeskConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Zendesk cursor-based incremental export
|--------------------------------------------------------------------------
|
| Every fixture here is synthesised from Zendesk's published documentation, not
| captured — contracts/fixtures/platforms/zendesk/README.md says field by field
| what is documented and what is inferred. Expectations are derived from the
| fixture at run time for the same reason they are in the App Store tests: the
| content is replaceable, the shape is what has to hold.
|
*/

const ZENDESK_SUBDOMAIN = 'example-help';
const ZENDESK_EMAIL = 'agent@example.invalid';
const ZENDESK_TOKEN = 'zdtok-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789';

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function zendeskConnector(int $maxPages = 20): ZendeskConnector
{
    return new ZendeskConnector(
        subdomain: ZENDESK_SUBDOMAIN,
        email: ZENDESK_EMAIL,
        apiToken: ZENDESK_TOKEN,
        baseUrlTemplate: 'https://{subdomain}.zendesk.com',
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        initialLookbackDays: 30,
        startTimeLagSeconds: 300,
    );
}

function zendeskFake(string $file, int $status = 200): void
{
    Http::fake(['*' => Http::response(PlatformFixture::raw('zendesk', $file), $status)]);
}

/**
 * @return array<string, mixed>
 */
function zendeskBody(string $file): array
{
    return PlatformFixture::json('zendesk', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function zendeskTickets(string $file): array
{
    /** @var list<array<string, mixed>> $tickets */
    $tickets = zendeskBody($file)['tickets'];

    return $tickets;
}

function zendeskFailure(callable $call): ConnectorFailure
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
| The request: API-token auth, and where the credential is allowed to appear
|--------------------------------------------------------------------------
*/

it('authenticates with the api token in the authorization header and nowhere else', function () {
    zendeskFake('page-1.json');

    zendeskConnector()->fetchPage(null);

    Http::assertSent(function ($request) {
        $expected = 'Basic '.base64_encode(ZENDESK_EMAIL.'/token:'.ZENDESK_TOKEN);

        return $request->header('Authorization') === [$expected]
            // Invariant I5 at the wire: the token is in exactly one place. A
            // credential in a URL ends up in every proxy and access log between
            // here and Zendesk, and in Laravel's own request logging.
            && ! str_contains($request->url(), ZENDESK_TOKEN)
            && ! str_contains($request->url(), ZENDESK_EMAIL)
            && $request->body() === '';
    });
});

it('asks the cursor-based incremental export of the configured subdomain', function () {
    zendeskFake('page-1.json');

    zendeskConnector()->fetchPage(null);

    Http::assertSent(fn ($request) => str_starts_with(
        $request->url(),
        'https://example-help.zendesk.com/api/v2/incremental/tickets/cursor.json?'
    ));
});

it('opens the first run with a start_time inside the configured lookback window', function () {
    CarbonImmutable::setTestNow('2026-09-02T12:00:00+00:00');
    zendeskFake('page-1.json');

    zendeskConnector()->fetchPage(null);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['start_time'] === (string) CarbonImmutable::now()->subDays(30)->getTimestamp()
            && ! isset($query['cursor']);
    });

    CarbonImmutable::setTestNow();
});

it('holds the first start_time behind the documented floor when the lookback is zero', function () {
    CarbonImmutable::setTestNow('2026-09-02T12:00:00+00:00');
    zendeskFake('page-1.json');

    (new ZendeskConnector(
        subdomain: ZENDESK_SUBDOMAIN,
        email: ZENDESK_EMAIL,
        apiToken: ZENDESK_TOKEN,
        baseUrlTemplate: 'https://{subdomain}.zendesk.com',
        limits: new ConnectorLimits(20, 3),
        timeout: 5,
        initialLookbackDays: 0,
        startTimeLagSeconds: 300,
    ))->fetchPage(null);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['start_time'] === (string) CarbonImmutable::now()->subSeconds(300)->getTimestamp();
    });

    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — the cursor, not a re-scan
|--------------------------------------------------------------------------
*/

it('continues from the stored cursor instead of restarting the export', function () {
    zendeskFake('page-2-end.json');

    $stored = zendeskBody('page-1.json')['after_cursor'];

    zendeskConnector()->fetchPage((new SyncCursor)->withToken($stored)->encode());

    Http::assertSent(function ($request) use ($stored) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // A start_time on a run that already has a cursor is a full re-scan.
        return $query['cursor'] === $stored && ! isset($query['start_time']);
    });
});

it('carries the platforms own cursor forward through the encoded cursor', function () {
    zendeskFake('page-1.json');

    $page = zendeskConnector()->fetchPage(null);

    expect(SyncCursor::decode($page->nextCursor)->token)->toBe(zendeskBody('page-1.json')['after_cursor'])
        ->and($page->hasMore)->toBeTrue();
});

it('keeps the encoded cursor inside the varchar(255) column', function () {
    zendeskFake('page-1.json');

    $page = zendeskConnector()->fetchPage(null);

    expect(strlen((string) $page->nextCursor))->toBeLessThan(255);
});

it('survives the runners promote-and-re-encode round trip without losing the cursor', function () {
    // IngestionRunner decodes the connector's cursor and re-encodes it to
    // promote the watermark. Anything SyncCursor does not know about is dropped
    // on that trip, which for this connector would mean restarting the export
    // from start_time on every single run.
    zendeskFake('page-1.json');

    $page = zendeskConnector()->fetchPage(null);
    $roundTripped = SyncCursor::decode($page->nextCursor)->promoted()->encode();

    expect(SyncCursor::decode($roundTripped)->token)->toBe(zendeskBody('page-1.json')['after_cursor']);
});

it('ends the run when the export says the stream ended', function () {
    zendeskFake('page-2-end.json');

    $page = zendeskConnector()->fetchPage(null);

    expect($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe(zendeskBody('page-2-end.json')['after_cursor']);
});

it('reports nothing new when the export is already caught up', function () {
    zendeskFake('page-caught-up.json');

    $page = zendeskConnector()->fetchPage(null);

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse()
        ->and($page->watermark)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| An empty page is not the end of the stream
|--------------------------------------------------------------------------
*/

it('keeps the stream open when a page comes back with no tickets', function () {
    zendeskFake('page-empty-continues.json');

    $page = zendeskConnector()->fetchPage(null);

    expect(zendeskTickets('page-empty-continues.json'))->toBeEmpty()
        ->and($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeTrue()
        ->and(SyncCursor::decode($page->nextCursor)->token)
        ->toBe(zendeskBody('page-empty-continues.json')['after_cursor']);
});

/*
|--------------------------------------------------------------------------
| Field mapping
|--------------------------------------------------------------------------
*/

it('maps a ticket onto a connector item', function () {
    zendeskFake('page-1.json');

    $tickets = zendeskTickets('page-1.json');
    $page = zendeskConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(count($tickets));

    $first = $tickets[0];
    $item = $page->items[0];

    expect($item->externalId)->toBe((string) $first['id'])
        ->and($item->author)->toBe($first['via']['source']['from']['name'])
        ->and($item->body)->toBe($first['description'])
        // created_at, not updated_at: an agent's later reply must not move the
        // customer's comment forward in the inbox or the trend charts.
        ->and($item->publishedAt)->toBe($first['created_at'])
        ->and($item->publishedAt)->not->toBe($first['updated_at'])
        ->and($item->sourceUrl)->toBe('https://example-help.zendesk.com/agent/tickets/'.$first['id'])
        ->and($item->rawPayload)->toBe($first);
});

it('projects the two-valued CSAT score onto the product rating scale', function () {
    zendeskFake('page-1.json');

    $page = zendeskConnector()->fetchPage(null);
    $byId = collect($page->items)->keyBy('externalId');

    expect($byId['1001']->rating)->toBe(1)      // score: bad
        ->and($byId['1002']->rating)->toBe(5)   // score: good
        ->and($byId['1003']->rating)->toBeNull(); // score: unoffered
});

it('treats an absent satisfaction rating as no rating rather than a bad one', function () {
    zendeskFake('page-2-end.json');

    $item = collect(zendeskConnector()->fetchPage(null)->items)->firstWhere('externalId', '1004');

    expect(zendeskTickets('page-2-end.json')[0]['satisfaction_rating'])->toBeNull()
        ->and($item->rating)->toBeNull();
});

it('skips a deleted ticket instead of ingesting an empty row', function () {
    zendeskFake('page-2-end.json');

    $deleted = collect(zendeskTickets('page-2-end.json'))->firstWhere('status', 'deleted');
    $page = zendeskConnector()->fetchPage(null);

    expect($deleted)->not->toBeNull()
        ->and(collect($page->items)->pluck('externalId')->all())->not->toContain((string) $deleted['id'])
        ->and($page->items)->toHaveCount(count(zendeskTickets('page-2-end.json')) - 1);
});

it('publishes the newest created_at on the page as the watermark', function () {
    zendeskFake('page-1.json');

    $newest = collect(zendeskTickets('page-1.json'))
        ->map(fn (array $t) => SyncCursor::parse($t['created_at'])?->getTimestamp())
        ->max();

    expect(SyncCursor::parse(zendeskConnector()->fetchPage(null)->watermark)?->getTimestamp())->toBe($newest);
});

it('tolerates a ticket with no description, no requester name and no rating', function () {
    $ticket = zendeskTickets('page-1.json')[0];
    unset($ticket['description'], $ticket['via'], $ticket['satisfaction_rating']);

    Http::fake(['*' => Http::response(json_encode([
        'tickets' => [$ticket],
        'after_cursor' => 'c1',
        'end_of_stream' => true,
    ]), 200)]);

    $item = zendeskConnector()->fetchPage(null)->items[0];

    expect($item->body)->toBe('')
        ->and($item->author)->toBeNull()
        ->and($item->rating)->toBeNull();
});

it('skips a ticket with no id rather than failing the page', function () {
    $tickets = zendeskTickets('page-1.json');
    $broken = $tickets[0];
    unset($broken['id']);

    Http::fake(['*' => Http::response(json_encode([
        'tickets' => [$broken, $tickets[1]],
        'after_cursor' => 'c1',
        'end_of_stream' => true,
    ]), 200)]);

    $page = zendeskConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe((string) $tickets[1]['id']);
});

/*
|--------------------------------------------------------------------------
| Failure mapping — every case is an existing ConnectorFailure
|--------------------------------------------------------------------------
*/

it('maps upstream status codes onto connector failures', function (string $file, int $status, ConnectorFailure $expected) {
    zendeskFake($file, $status);

    expect(zendeskFailure(fn () => zendeskConnector()->fetchPage(null)))->toBe($expected);
})->with([
    'unauthorized' => ['error-unauthorized.json', 401, ConnectorFailure::InvalidCredentials],
    'forbidden' => ['error-forbidden.json', 403, ConnectorFailure::InvalidCredentials],
    'rate limited' => ['error-rate-limited.json', 429, ConnectorFailure::RateLimited],
    'start_time too recent' => ['error-invalid-start-time.json', 422, ConnectorFailure::Misconfigured],
]);

it('maps the remaining status codes onto connector failures', function (int $status, ConnectorFailure $expected) {
    Http::fake(['*' => Http::response('{}', $status)]);

    expect(zendeskFailure(fn () => zendeskConnector()->fetchPage(null)))->toBe($expected);
})->with([
    [404, ConnectorFailure::Misconfigured],
    [500, ConnectorFailure::Unreachable],
    [502, ConnectorFailure::Unreachable],
    [503, ConnectorFailure::Unreachable],
]);

it('treats a connection failure as unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(zendeskFailure(fn () => zendeskConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::Unreachable);
});

it('refuses a body it cannot recognise as an incremental export', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(zendeskFailure(fn () => zendeskConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'not json' => ['not json'],
    'a list' => ['[]'],
    'no tickets key' => ['{"end_of_stream":true}'],
    'tickets is not a list' => ['{"tickets":"nope","end_of_stream":true}'],
    // Without end_of_stream there is no stop condition, and guessing either way
    // is worse than refusing: true loses the rest of the stream, false loops.
    'no end_of_stream' => ['{"tickets":[],"after_cursor":"c1"}'],
    'end_of_stream is not a bool' => ['{"tickets":[],"after_cursor":"c1","end_of_stream":"no"}'],
    // hasMore with nowhere to continue would restart the export from start_time
    // on every run, which is the full re-scan spec 6.1 forbids.
    'continues with no cursor' => ['{"tickets":[],"end_of_stream":false}'],
]);

it('keeps the stored cursor when the final page carries no new one', function () {
    // A last page with no after_cursor must not blank the position: the next
    // run would then fall back to start_time and re-scan the whole window.
    Http::fake(['*' => Http::response(json_encode([
        'tickets' => [],
        'end_of_stream' => true,
    ]), 200)]);

    $page = zendeskConnector()->fetchPage((new SyncCursor)->withToken('tok-stored')->encode());

    expect($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe('tok-stored');
});

it('refuses a cursor too long to fit the column it has to be stored in', function () {
    Http::fake(['*' => Http::response(json_encode([
        'tickets' => [],
        'after_cursor' => str_repeat('x', 121),
        'end_of_stream' => false,
    ]), 200)]);

    // Storing it would overflow integrations.sync_cursor and fail the run with
    // a database error that says nothing useful about the cause.
    expect(zendeskFailure(fn () => zendeskConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential never reaches anything that can be persisted
|--------------------------------------------------------------------------
*/

it('never puts the credential into a failure, its message or its stack trace', function (int $status) {
    Http::fake(['*' => Http::response(
        // The worst case: an upstream that echoes the credential straight back.
        json_encode(['error' => 'rejected', 'sent' => ZENDESK_TOKEN, 'as' => ZENDESK_EMAIL]),
        $status
    )]);

    try {
        zendeskConnector()->fetchPage(null);
        $this->fail('Expected a ConnectorException.');
    } catch (ConnectorException $e) {
        expect($e->getMessage())->not->toContain(ZENDESK_TOKEN)
            ->and($e->getMessage())->not->toContain(ZENDESK_EMAIL)
            ->and($e->getSafeMessage())->not->toContain(ZENDESK_TOKEN)
            ->and($e->getSafeMessage())->not->toContain(ZENDESK_EMAIL)
            // PHP puts scalar call arguments into a trace. The credentials are
            // constructor arguments, so no frame on this stack carries them —
            // but the assertion is cheap and the failure mode is silent.
            ->and($e->getTraceAsString())->not->toContain(ZENDESK_TOKEN)
            ->and($e->getTraceAsString())->not->toContain(ZENDESK_EMAIL);
    }
})->with([401, 403, 422, 429, 500]);

/*
|--------------------------------------------------------------------------
| Health and limits
|--------------------------------------------------------------------------
*/

it('reports itself healthy when the export answers', function () {
    zendeskFake('page-caught-up.json');

    expect(zendeskConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports the safe failure message when the credentials are rejected', function () {
    zendeskFake('error-unauthorized.json', 401);

    $health = zendeskConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->message())->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
        ->and($health->message())->not->toContain(ZENDESK_TOKEN);
});

it('exposes the configured ceilings', function () {
    $limits = zendeskConnector()->limits();

    expect($limits->maxPagesPerRun)->toBe(20)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The fixtures themselves — decision D-06
|--------------------------------------------------------------------------
|
| contracts/fixtures/platforms/zendesk/README.md promises no real customer, no
| real agent and no real credential is in these files. This asserts the promise
| rather than restating it, so a future edit — or a re-derivation against a live
| account — cannot quietly bring one in.
|
*/

it('holds no real requester identity on any recorded ticket', function (string $file) {
    foreach (zendeskTickets($file) as $ticket) {
        $from = $ticket['via']['source']['from'] ?? null;

        if ($from === null) {
            continue;
        }

        expect($from['name'])->toMatch('/^requester-\d+$/')
            // The requester's address is derived from their name, not a free
            // value, so the two cannot drift apart.
            ->and($from['address'])->toBe($from['name'].'@example.invalid');
    }
})->with(['page-1.json', 'page-2-end.json']);

it('keeps every recorded address and host inside the synthetic account', function (string $file) {
    $raw = PlatformFixture::raw('zendesk', $file);

    preg_match_all('/[\w.+-]+@[\w.-]+/', $raw, $addresses);

    // Either a requester on the reserved-and-unresolvable .invalid TLD (RFC
    // 2606), or the fixture account's own support inbox on the one subdomain
    // these tests are configured against — never anything else. Filtered down
    // to what fails, rather than asserted per element in a foreach, so a page
    // with zero addresses (the error bodies) still exercises the assertion
    // instead of silently skipping it.
    $unexpectedAddresses = array_values(array_filter(
        $addresses[0],
        static fn (string $address): bool => $address !== 'support@'.ZENDESK_SUBDOMAIN.'.zendesk.com'
            && ! str_ends_with($address, '@example.invalid'),
    ));

    preg_match_all('#https?://([^/"]+)#', $raw, $hosts);

    $unexpectedHosts = array_values(array_unique(array_filter(
        $hosts[1],
        static fn (string $host): bool => $host !== ZENDESK_SUBDOMAIN.'.zendesk.com',
    )));

    expect($unexpectedAddresses)->toBe([])
        ->and($unexpectedHosts)->toBe([]);
})->with([
    'page-1.json', 'page-2-end.json', 'page-empty-continues.json', 'page-caught-up.json',
    'error-unauthorized.json', 'error-forbidden.json', 'error-rate-limited.json', 'error-invalid-start-time.json',
]);
