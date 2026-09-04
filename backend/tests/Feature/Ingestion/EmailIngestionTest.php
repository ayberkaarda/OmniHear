<?php

use App\Events\FeedbackIngested;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\EmailConnector;
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
| A shared mailbox, end to end — IngestionRunner against the recorded pages
|--------------------------------------------------------------------------
|
| ConnectorFactory and config/connectors.php are the main thread's files and do
| not know this platform yet, so the connector is constructed directly and
| injected through the same StubConnectorFactory the rest of the ingestion suite
| uses. Everything from IngestionRunner down is the real path: the page loop,
| the ON CONFLICT insert, the cursor promotion rule, the PII masking, the
| failure recording.
|
| The fixture envelopes were recorded from a live JMAP account on 2026-09-05 and
| the messages inside them were written for this repository — see
| contracts/fixtures/platforms/email/README.md for which is which, and for what
| the recording settled, falsified and left inferred.
|
| The fake dispatches on the request body, never on a call counter: one logical
| page here is up to three HTTP calls and a second run legitimately starts the
| sequence over, so a counter would hand back the wrong fixture the moment
| anything repeats.
|
*/

const EMI_SESSION_URL = 'https://jmap.example.invalid/.well-known/jmap';
const EMI_TOKEN = 'jmap-LIVE-zyxwvutsrqponmlkjihgfedcba-9876543210';
const EMI_MAILBOX = 'Support';
const EMI_MAILBOX_ID = 'Mb6';
const EMI_PAGE_SIZE = 3;

/** @return array{0: Company, 1: Integration} */
function emiIntegration(array $attributes = []): array
{
    $company = Company::factory()->create();

    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'email',
        'settings' => [],
        // All three are credentials (the W11 contract): the session URL names
        // the host every authenticated request goes to, and the mailbox name is
        // part of what identifies the account's data.
        'credentials' => [
            'session_url' => EMI_SESSION_URL,
            'api_token' => EMI_TOKEN,
            'mailbox' => EMI_MAILBOX,
        ],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ], $attributes));

    return [$company, $integration];
}

function emiWired(int $maxPages = 20): EmailConnector
{
    $connector = new EmailConnector(
        sessionUrl: EMI_SESSION_URL,
        apiToken: EMI_TOKEN,
        mailbox: EMI_MAILBOX,
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        pageSize: EMI_PAGE_SIZE,
        maxBodyBytes: 8192,
        initialLookbackDays: 30,
    );

    useConnector($connector);

    return $connector;
}

function emiRaw(string $file): string
{
    return PlatformFixture::raw('email', $file);
}

/**
 * @return array<string, mixed>
 */
function emiArgs(string $file, string $name): array
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: string}> $responses */
    $responses = PlatformFixture::json('email', $file)['methodResponses'];

    foreach ($responses as $triple) {
        if ($triple[0] === $name) {
            return $triple[1];
        }
    }

    throw new RuntimeException("No {$name} response in {$file}.");
}

/**
 * @return list<array<string, mixed>>
 */
function emiEmails(string $file): array
{
    /** @var list<array<string, mixed>> $list */
    $list = emiArgs($file, 'Email/get')['list'];

    return $list;
}

/**
 * @return array<string, mixed>
 */
function emiEmail(string $file, string $id): array
{
    foreach (emiEmails($file) as $email) {
        if ($email['id'] === $id) {
            return $email;
        }
    }

    throw new RuntimeException("No message {$id} in {$file}.");
}

/**
 * The token a fixture leaves behind.
 *
 * `Email/changes.newState` when the fixture is a change response, and only then
 * the chained `Email/get.state`. The live recording showed the two differ on a
 * capped change window: `newState` sits part-way through the change log while
 * `Email/get` answers the account's current state, so taking the token from
 * `Email/get` would silently skip every change between them.
 */
function emiState(string $file): string
{
    try {
        return (string) emiArgs($file, 'Email/changes')['newState'];
    } catch (RuntimeException) {
        return (string) emiArgs($file, 'Email/get')['state'];
    }
}

function emiCursor(string $token): string
{
    return (new SyncCursor)->withToken($token)->encode();
}

/**
 * The external ids a set of fixtures should produce.
 *
 * Two rules, both derived here rather than hard-coded, because both are the
 * point of this connector: a message outside the watched mailbox is not
 * feedback at all, and a message with neither a subject nor any text is a blank
 * row that would spend a unit of analysis quota on nothing.
 *
 * @return list<string>
 */
function emiIngestableIds(string ...$files): array
{
    $ids = [];

    foreach ($files as $file) {
        foreach (emiEmails($file) as $email) {
            if (($email['mailboxIds'][EMI_MAILBOX_ID] ?? null) !== true) {
                continue;
            }

            $subject = trim((string) ($email['subject'] ?? ''));
            $preview = trim((string) ($email['preview'] ?? ''));
            $text = '';

            foreach (($email['textBody'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'text/plain') {
                    $text .= trim((string) ($email['bodyValues'][$part['partId']]['value'] ?? ''));
                }
            }

            if ($subject === '' && $text === '' && $preview === '') {
                continue;
            }

            $ids[] = (string) $email['id'];
        }
    }

    sort($ids);

    return $ids;
}

/**
 * @param  array<string, array{0: string, 1: int}>|null  $script
 * @return array<string, array{0: string, 1: int}>
 */
function emiScript(?array $script = null): array
{
    static $current = [];

    if ($script !== null) {
        $current = $script;
    }

    return $current;
}

function emiKey(object $request): string
{
    if (strtoupper((string) $request->method()) === 'GET') {
        return 'session';
    }

    /** @var array<string, mixed>|null $body */
    $body = json_decode((string) $request->body(), true);
    $call = $body['methodCalls'][0] ?? null;

    if (! is_array($call) || ! is_string($call[0] ?? null)) {
        return 'unknown';
    }

    if ($call[0] === 'Email/changes') {
        return 'Email/changes:'.(string) ($call[1]['sinceState'] ?? '');
    }

    return $call[0];
}

/**
 * A mutable script behind one installed closure.
 *
 * **`Http::fake()` merges stub callbacks rather than replacing them**: a second
 * `Http::fake(closure)` leaves the first one in charge, so a test that re-arms
 * the fake for its second phase silently keeps getting the first phase's
 * answers. That cost both W8 connector tracks a debugging pass. Re-arming the
 * script the installed closure reads is the only form that works.
 *
 * @param  array<string, array{0: string, 1: int}>  $script
 */
function emiServe(array $script): void
{
    emiScript($script);

    Http::fake(function ($request) {
        $script = emiScript();
        $key = emiKey($request);
        $entry = $script[$key] ?? $script['*'] ?? null;

        if ($entry === null) {
            throw new RuntimeException("Nothing scripted for {$key}.");
        }

        return Http::response($entry[0], $entry[1]);
    });
}

/**
 * @param  array<string, array{0: string, 1: int}>  $overrides
 */
function emiServeDefault(array $overrides = []): void
{
    emiServe(array_merge([
        'session' => [emiRaw('session.json'), 200],
        'Mailbox/get' => [emiRaw('mailboxes.json'), 200],
        'Email/query' => [emiRaw('page-1.json'), 200],
        'Email/changes:'.emiState('page-1.json') => [emiRaw('changes-1.json'), 200],
        'Email/changes:'.emiState('changes-1.json') => [emiRaw('changes-2-last.json'), 200],
        'Email/changes:'.emiState('changes-2-last.json') => [emiRaw('changes-none.json'), 200],
    ], $overrides));
}

/**
 * Capture what actually reaches the log, rendered, rather than trusting a spy's
 * arguments: a credential can arrive through the context array and only become
 * visible once Monolog formats it.
 */
function emiCaptureLog(Closure $run): string
{
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    $run();

    return collect($handler->getRecords())
        ->map(fn ($record) => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->implode("\n");
}

beforeEach(function () {
    RateLimiter::clear('connector:email');
    // Faked because the queue runs sync in tests: without it FeedbackIngested
    // reaches the analysis listener, which calls the real analyzer over HTTP.
    // That service is up in the dev stack and absent in CI (docs/LESSONS.md).
    Event::fake([FeedbackIngested::class]);
});

/*
|--------------------------------------------------------------------------
| The cold start
|--------------------------------------------------------------------------
*/

it('ingests the first page of the mailbox and stores the server state as the cursor', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(emiIngestableIds('page-1.json'))
        ->and($stored)->toHaveCount(3);

    $cursor = SyncCursor::decode(asTenant(
        $company,
        fn () => Integration::query()->findOrFail($integration->id)->sync_cursor,
    ));

    // Token only. No published_at watermark is used at all: Email/changes is
    // ordered by change, not by receivedAt, so a high-water mark would drop an
    // old message that was just filed into the mailbox.
    expect($cursor->token)->toBe(emiState('page-1.json'))
        ->and($cursor->watermark)->toBeNull()
        ->and($cursor->pending)->toBeNull();

    Event::assertDispatchedTimes(FeedbackIngested::class, 3);
});

it('stores the mapped fields of a message under the right tenant', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);

    $email = emiEmail('page-1.json', 'Emsg00000001');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $email['id'])
        ->firstOrFail());

    expect($row->company_id)->toBe($company->id)
        ->and($row->integration_id)->toBe($integration->id)
        ->and($row->author)->toBe($email['from'][0]['name'])
        // The documented decision: the subject and the text are one string,
        // because the analyzer reads feedbacks.body and nothing else and an
        // e-mail's sentiment usually lives in its subject line.
        ->and($row->body)->toBe($email['subject']."\n\n".$email['bodyValues']['1']['value'])
        // JMAP publishes no canonical web page for a message.
        ->and($row->source_url)->toBeNull()
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        // Compared as an instant, not as a string: toDateTimeString() drops the
        // offset and has already put a row seven hours off the real one.
        ->and($row->published_at?->equalTo(SyncCursor::parse($email['receivedAt'])))->toBeTrue()
        ->and($row->raw_payload['id'])->toBe($email['id']);
});

it('carries no rating through to the stored row', function () {
    // **There is no `feedbacks.rating` column** — ConnectorItem::$rating reaches
    // the database only inside raw_payload, and on this channel it is null
    // anyway because e-mail carries no rating.
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);

    $payloads = asTenant($company, fn () => Feedback::query()
        ->orderBy('external_id')
        ->pluck('raw_payload')
        ->all());

    expect($payloads)->toHaveCount(3);

    foreach ($payloads as $payload) {
        expect($payload)->not->toHaveKey('rating');
    }
});

it('masks the sender address out of the raw payload it keeps', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);

    $email = emiEmail('page-1.json', 'Emsg00000001');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $email['id'])
        ->firstOrFail());

    // Spec 8: the direct identifier is not retained. The connector already
    // declines to request `to`/`cc`/`bcc`; this is the runner's pass over what
    // did arrive.
    expect($row->raw_payload['from'][0]['email'])->toBe('[email]')
        ->and(json_encode($row->raw_payload))->not->toContain($email['from'][0]['email']);
});

it('joins the subject onto a message whose body is empty rather than dropping it', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration(['sync_cursor' => emiCursor(emiState('changes-1.json'))]);

    runFetch($company, $integration);

    $email = emiEmail('changes-2-last.json', 'Emsg00000006');

    // The premise: no words anywhere but the subject line.
    expect(trim((string) $email['bodyValues']['1']['value']))->toBe('')
        ->and(trim((string) $email['preview']))->toBe('');

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', $email['id'])
        ->firstOrFail());

    expect($row->body)->toBe(trim((string) $email['subject']));
});

/*
|--------------------------------------------------------------------------
| The account-wide Email/changes correction
|--------------------------------------------------------------------------
*/

it('never ingests a message that lives outside the watched mailbox', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration(['sync_cursor' => emiCursor(emiState('page-1.json'))]);

    runFetch($company, $integration);

    $outsider = emiEmail('changes-1.json', 'Emsg00000005');

    // The premise: Email/changes is account-wide (RFC 8621 section 4.3), so the
    // server really did hand this message to the connector.
    expect($outsider['mailboxIds'])->not->toHaveKey(EMI_MAILBOX_ID)
        ->and($outsider['mailboxIds'])->toHaveKey('Mb1');

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    // Without the client-side membership check a company watching "Support"
    // would ingest its own Sent and Archive folders as customer feedback.
    expect($stored)->toBe(emiIngestableIds('changes-1.json', 'changes-2-last.json'))
        ->and($stored)->not->toContain($outsider['id'])
        ->and($stored)->toBe(['Emsg00000004', 'Emsg00000006', 'Emsg00000007']);
});

it('walks the whole change chain in one run and promotes the last state', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration(['sync_cursor' => emiCursor(emiState('page-1.json'))]);

    runFetch($company, $integration);

    $cursor = SyncCursor::decode(asTenant(
        $company,
        fn () => Integration::query()->findOrFail($integration->id)->sync_cursor,
    ));

    // Two pages: hasMoreChanges=true, then false. The runner promotes because
    // the connector said the stream ended, not because the cap was reached.
    expect($cursor->token)->toBe(emiState('changes-2-last.json'))
        ->and($cursor->page)->toBe(1)
        ->and(Http::recorded()->count())->toBe(4);
});

it('leaves the run to the runner cap without claiming the stream ended', function () {
    emiServeDefault();
    emiWired(maxPages: 1);
    [$company, $integration] = emiIntegration(['sync_cursor' => emiCursor(emiState('page-1.json'))]);

    runFetch($company, $integration);

    $cursor = SyncCursor::decode(asTenant(
        $company,
        fn () => Integration::query()->findOrFail($integration->id)->sync_cursor,
    ));

    // One page fetched, and the position stored is the one that page reached —
    // not a later one the run never got to. A connector that reported
    // hasMore=false on the cap would have buried the rest (docs/LESSONS.md).
    expect($cursor->token)->toBe(emiState('changes-1.json'))
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same message twice is one row
|--------------------------------------------------------------------------
*/

it('creates no duplicate row when the same page is served twice', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the server genuinely serves the same messages again
    // and UNIQUE (integration_id, external_id), not the cursor, is what stops
    // them becoming rows.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    emiServeDefault();
    Event::fake([FeedbackIngested::class]);

    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBe(3);

    // Re-firing would re-analyse the message and burn a second unit of quota,
    // which is the whole reason I2 exists.
    Event::assertNotDispatched(FeedbackIngested::class);
});

it('ingests nothing on a run where the mailbox did not change', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => emiCursor(emiState('changes-2-last.json'))])->save());

    emiServeDefault();
    Event::fake([FeedbackIngested::class]);

    runFetch($company, $integration->fresh());

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBe(3);

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('recovers from a state the server can no longer answer from', function () {
    emiServeDefault([
        'Email/changes:stale-state' => [emiRaw('changes-cannot-calculate.json'), 200],
        'Email/query' => [emiRaw('page-recovered.json'), 200],
    ]);
    emiWired();
    [$company, $integration] = emiIntegration(['sync_cursor' => emiCursor('stale-state')]);

    runFetch($company, $integration);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    // A branch, not a failure: the integration stays active and takes a fresh
    // state to be incremental from.
    expect($stored)->toBe(emiIngestableIds('page-recovered.json'));

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('active')
        ->and($reloaded->sync_error)->toBeNull()
        ->and(SyncCursor::decode($reloaded->sync_cursor)->token)->toBe(emiState('page-recovered.json'));
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential path
|--------------------------------------------------------------------------
*/

it('writes a sync_error that carries no credential material', function (int $status, string $file, string $expected) {
    // The upstream echoes the token back in its error body — the worst
    // realistic case, and the one a message built from a response would leak.
    emiServe(['*' => [(string) json_encode([
        'message' => 'UPSTREAM-ECHO-BODY',
        'token' => EMI_TOKEN,
        'body' => emiRaw($file),
    ]), $status]]);
    emiWired();
    [$company, $integration] = emiIntegration();

    try {
        runFetchDirect($company, $integration);
    } catch (Throwable) {
        // Transient failures are rethrown for the queue. The recorded state is
        // what this test is about.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)->toBe($expected)
        ->and($reloaded->sync_error)->not->toContain(EMI_TOKEN)
        ->and($reloaded->sync_error)->not->toContain('UPSTREAM-ECHO-BODY')
        ->and($reloaded->status)->toBe('error');
})->with([
    'rejected token' => [401, 'error-unauthorized.txt', 'The platform rejected the integration credentials.'],
    'unreadable account' => [403, 'error-forbidden.json', 'The platform rejected the integration credentials.'],
    'no session resource' => [404, 'error-not-found.json', 'The integration settings are incomplete for this platform.'],
    'refused request' => [400, 'error-not-request.json', 'The integration settings are incomplete for this platform.'],
    'upstream down' => [500, 'error-unauthorized.txt', 'The platform could not be reached.'],
]);

it('logs nothing that contains the api token, on the failure path or the happy one', function () {
    emiWired();
    [$company, $integration] = emiIntegration();

    $failing = emiCaptureLog(function () use ($company, $integration) {
        // The upstream echoes the token back at us in its error body.
        emiServe(['*' => [(string) json_encode(['message' => EMI_TOKEN]), 401]]);

        try {
            runFetchDirect($company, $integration);
        } catch (Throwable) {
        }
    });

    $happy = emiCaptureLog(function () use ($company, $integration) {
        emiServeDefault();
        runFetch($company, $integration->fresh());
    });

    foreach ([$failing, $happy] as $written) {
        expect($written)->not->toContain(EMI_TOKEN)
            ->and($written)->not->toContain('api_token')
            ->and($written)->not->toContain('Bearer')
            ->and($written)->not->toContain('"credentials"');
    }

    // The failure path has to have written *something*, and the happy path has
    // to have actually been happy — otherwise both halves prove nothing. The
    // missing second assertion is exactly what let this trap through twice.
    expect($failing)->not->toBe('')
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(3);
});

it('never sends the api token anywhere but the authorization header', function () {
    emiServeDefault();
    emiWired();
    [$company, $integration] = emiIntegration();

    runFetch($company, $integration);

    expect(Http::recorded()->count())->toBe(3);

    Http::assertSent(function ($request) {
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            $headers[strtolower((string) $name)] = $values;
        }

        return ($headers['authorization'] ?? null) === ['Bearer '.EMI_TOKEN]
            && ! str_contains($request->url(), EMI_TOKEN)
            && ! str_contains((string) $request->body(), EMI_TOKEN);
    });
});

it('keeps the credential out of everything that leaves the database', function () {
    [$company, $integration] = emiIntegration();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect(json_encode($reloaded))->not->toContain(EMI_TOKEN)
        ->and(json_encode($reloaded->toArray()))->not->toContain(EMI_TOKEN)
        // The credential is still there — hidden, not lost.
        ->and($reloaded->credentials['api_token'])->toBe(EMI_TOKEN);
});
