<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\EmailConnector;
use App\Support\Connectors\SyncCursor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| A shared mailbox over JMAP
|--------------------------------------------------------------------------
|
| Every fixture here is synthesised from RFC 8620 and RFC 8621, not captured —
| contracts/fixtures/platforms/email/README.md says, with section numbers, what
| the RFCs document and what is inferred. Expectations are derived from the
| fixture at run time for the same reason they are in the App Store, Zendesk and
| Trustpilot tests: the content is replaceable (these files are due to be
| re-recorded against a live account, envelope-real), the shape is what has to
| hold.
|
| **The fake dispatches on the request, never on a call counter.** One logical
| page of this connector is up to three HTTP calls — a session GET, a
| Mailbox/get and the JMAP request itself — and a legitimate second run starts
| the sequence over, so a counter answers the wrong fixture the moment anything
| retries. The key comes out of the request body: the method name, and for
| Email/changes the `sinceState` it was asked about. That also means the state
| chain in the fixtures is what drives paging, exactly as a server would.
|
| The connector is built with `pageSize: 3`, which is what page-1.json holds.
|
*/

const EMC_SESSION_URL = 'https://jmap.example.invalid/.well-known/jmap';
const EMC_TOKEN = 'jmap-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789';
const EMC_MAILBOX = 'Support';
const EMC_MAILBOX_ID = 'mbx-support';
const EMC_PAGE_SIZE = 3;
const EMC_LOOKBACK = 30;

function emcConnector(
    int $maxPages = 20,
    int $pageSize = EMC_PAGE_SIZE,
    string $mailbox = EMC_MAILBOX,
    int $lookbackDays = EMC_LOOKBACK,
    string $sessionUrl = EMC_SESSION_URL,
): EmailConnector {
    return new EmailConnector(
        sessionUrl: $sessionUrl,
        apiToken: EMC_TOKEN,
        mailbox: $mailbox,
        limits: new ConnectorLimits($maxPages, 3),
        timeout: 5,
        pageSize: $pageSize,
        maxBodyBytes: 8192,
        initialLookbackDays: $lookbackDays,
    );
}

function emcRaw(string $file): string
{
    return PlatformFixture::raw('email', $file);
}

/**
 * The arguments of one method response in a fixture.
 *
 * @return array<string, mixed>
 */
function emcArgs(string $file, string $name): array
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
function emcEmails(string $file): array
{
    /** @var list<array<string, mixed>> $list */
    $list = emcArgs($file, 'Email/get')['list'];

    return $list;
}

/**
 * @return array<string, mixed>
 */
function emcEmail(string $file, string $id): array
{
    foreach (emcEmails($file) as $email) {
        if ($email['id'] === $id) {
            return $email;
        }
    }

    throw new RuntimeException("No message {$id} in {$file}.");
}

/** The server state a fixture's Email/get establishes. */
function emcState(string $file): string
{
    return (string) emcArgs($file, 'Email/get')['state'];
}

function emcCursor(string $token): string
{
    return (new SyncCursor)->withToken($token)->encode();
}

/**
 * The fixture with one property of one message overwritten, re-encoded.
 *
 * A derivation, not a second fixture: the byte content of these files is
 * replaceable and the parity test compares both copies, so a branch that needs
 * a variant of a recorded message builds it from the recorded one rather than
 * adding a file whose only purpose is a single field.
 */
function emcVariant(string $file, string $id, string $property, mixed $value): string
{
    $decoded = PlatformFixture::json('email', $file);

    /** @var list<array{0: string, 1: array<string, mixed>, 2: string}> $responses */
    $responses = $decoded['methodResponses'];

    foreach ($responses as $i => $triple) {
        if ($triple[0] !== 'Email/get') {
            continue;
        }

        /** @var list<array<string, mixed>> $list */
        $list = $triple[1]['list'];

        foreach ($list as $j => $email) {
            if ($email['id'] === $id) {
                $decoded['methodResponses'][$i][1]['list'][$j][$property] = $value;
            }
        }
    }

    return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * A fixture with a mutation applied, re-encoded.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $mutate
 */
function emcMutated(string $file, Closure $mutate): string
{
    return (string) json_encode(
        $mutate(PlatformFixture::json('email', $file)),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}

/**
 * One way a conforming-looking server can answer something this connector
 * cannot read, as `[dispatch key, body]`.
 *
 * A `match` rather than closures in a dataset: the dataset is built while the
 * test file is being collected, and nothing here should depend on the
 * application being booted at that moment.
 *
 * @return array{0: string, 1: string}
 */
function emcBroken(string $case): array
{
    return match ($case) {
        'session is not an object' => ['session', '"not an object"'],
        'session names no mail account' => ['session', emcMutated('session.json', function (array $session): array {
            unset($session['primaryAccounts']['urn:ietf:params:jmap:mail']);

            return $session;
        })],
        'the mailbox list is not a list' => ['Mailbox/get', emcMutated('mailboxes.json', function (array $body): array {
            $body['methodResponses'][0][1]['list'] = 'nope';

            return $body;
        })],
        'the query answer is under a different call id' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][0][2] = 'zz';

            return $body;
        })],
        'the query answer is a different method' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][0][0] = 'Foo/bar';

            return $body;
        })],
        'the method name is not a string' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][0][0] = 42;

            return $body;
        })],
        'the get carries no state' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][1][1]['state'] = '';

            return $body;
        })],
        'the get list is not a list' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][1][1]['list'] = 'nope';

            return $body;
        })],
        'the account refuses the read' => ['Email/query', emcMutated('page-1.json', function (array $body): array {
            $body['methodResponses'][0] = ['error', ['type' => 'forbidden'], 'q0'];

            return $body;
        })],
        default => throw new RuntimeException("No broken case {$case}."),
    };
}

/**
 * What the fake answers, keyed by what the request actually asked for.
 *
 * A mutable holder rather than a fresh `Http::fake()` per phase, because
 * **`Http::fake()` merges stub callbacks, it does not replace them**: the
 * closure registered first keeps answering and a re-armed fake is silently
 * ignored. One installed closure reading this holder is the only form that lets
 * a later phase answer differently.
 *
 * @param  array<string, array{0: string, 1: int}>|null  $script
 * @return array<string, array{0: string, 1: int}>
 */
function emcScript(?array $script = null): array
{
    static $current = [];

    if ($script !== null) {
        $current = $script;
    }

    return $current;
}

/**
 * The dispatch key of one outgoing request.
 *
 * `session` for the session GET, the method name for a JMAP request, and for
 * `Email/changes` the method name plus the state it asked about — so a run that
 * legitimately walks the state chain gets the next page, and a run that starts
 * over gets the first one again.
 */
function emcKey(object $request): string
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
 * @param  array<string, array{0: string, 1: int}>  $script
 */
function emcServe(array $script): void
{
    emcScript($script);

    Http::fake(function ($request) {
        $script = emcScript();
        $key = emcKey($request);
        $entry = $script[$key] ?? $script['*'] ?? null;

        if ($entry === null) {
            throw new RuntimeException("Nothing scripted for {$key}.");
        }

        return Http::response($entry[0], $entry[1]);
    });
}

/**
 * The whole recorded conversation: session, mailboxes, the cold-start page and
 * the state chain through both change pages into the quiet one.
 *
 * @param  array<string, array{0: string, 1: int}>  $overrides
 * @return array<string, array{0: string, 1: int}>
 */
function emcDefaultScript(array $overrides = []): array
{
    return array_merge([
        'session' => [emcRaw('session.json'), 200],
        'Mailbox/get' => [emcRaw('mailboxes.json'), 200],
        'Email/query' => [emcRaw('page-1.json'), 200],
        'Email/changes:'.emcState('page-1.json') => [emcRaw('changes-1.json'), 200],
        'Email/changes:'.emcState('changes-1.json') => [emcRaw('changes-2-last.json'), 200],
        'Email/changes:'.emcState('changes-2-last.json') => [emcRaw('changes-none.json'), 200],
    ], $overrides);
}

/**
 * @param  array<string, array{0: string, 1: int}>  $overrides
 */
function emcServeDefault(array $overrides = []): void
{
    emcServe(emcDefaultScript($overrides));
}

/**
 * Headers keyed lower-case: header names are case-insensitive on the wire and
 * this suite must not pass or fail on the casing the client happens to use.
 *
 * @return array<string, list<string>>
 */
function emcHeaders(object $request): array
{
    $headers = [];

    /** @var array<string, list<string>> $raw */
    $raw = $request->headers();

    foreach ($raw as $name => $values) {
        $headers[strtolower((string) $name)] = $values;
    }

    return $headers;
}

/**
 * The arguments of the first method call of a request that was sent.
 *
 * @return array<string, mixed>
 */
function emcSentArguments(string $method): array
{
    foreach (Http::recorded() as [$request]) {
        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $request->body(), true);
        $call = $body['methodCalls'][0] ?? null;

        if (is_array($call) && ($call[0] ?? null) === $method) {
            /** @var array<string, mixed> $arguments */
            $arguments = $call[1];

            return $arguments;
        }
    }

    throw new RuntimeException("No {$method} request was sent.");
}

function emcFailure(callable $call): ConnectorFailure
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
| Invariant I5 — where the token is allowed to appear
|--------------------------------------------------------------------------
*/

it('sends the api token in the authorization header and nowhere else', function () {
    emcServeDefault();

    emcConnector()->fetchPage(null);

    expect(Http::recorded())->not->toBeEmpty();

    Http::assertSent(function ($request) {
        $headers = emcHeaders($request);

        return ($headers['authorization'] ?? null) === ['Bearer '.EMC_TOKEN]
            && ! str_contains($request->url(), EMC_TOKEN)
            && ! str_contains((string) $request->body(), EMC_TOKEN);
    });
});

it('refuses to send the token to a session url that is not https', function (string $url) {
    expect(emcFailure(fn () => emcConnector(sessionUrl: $url)))
        ->toBe(ConnectorFailure::Misconfigured);
})->with([
    'plain http' => ['http://jmap.example.invalid/.well-known/jmap'],
    'a local file' => ['file:///etc/passwd'],
    'not a url at all' => ['jmap.example.invalid'],
]);

it('refuses to follow a session document that points the token at a plain-http api', function () {
    $session = PlatformFixture::json('email', 'session.json');
    $session['apiUrl'] = 'http://attacker.example.invalid/jmap/api/';

    emcServeDefault(['session' => [(string) json_encode($session), 200]]);

    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
});

/*
|--------------------------------------------------------------------------
| The session and the mailbox — two extra requests per run, not per page
|--------------------------------------------------------------------------
*/

it('resolves the session and the mailbox once for the lifetime of the connector', function () {
    emcServeDefault();

    $connector = emcConnector();
    $connector->fetchPage(null);
    $connector->fetchPage(emcCursor(emcState('page-1.json')));

    $keys = Http::recorded()->map(fn ($pair) => emcKey($pair[0]))->all();

    // Session and mailbox once each, then one request per page: "one request
    // per page plus two per run", which is the claim the contract makes.
    expect($keys)->toBe([
        'session',
        'Mailbox/get',
        'Email/query',
        'Email/changes:'.emcState('page-1.json'),
    ]);
});

it('reads the whole mailbox list rather than asking the server to match the name', function () {
    emcServeDefault();

    emcConnector()->fetchPage(null);

    // RFC 8621 section 2.3: `Mailbox/query`'s `name` condition is a substring
    // match, so asking the server for "Support" can silently answer with
    // "Support Archive". The list is fetched and matched here instead.
    expect(emcSentArguments('Mailbox/get')['ids'])->toBeNull()
        ->and(emcSentArguments('Email/query')['filter']['inMailbox'])->toBe(EMC_MAILBOX_ID);

    Http::assertNotSent(fn ($request) => str_contains((string) $request->body(), 'Mailbox/query'));
});

it('matches the mailbox name exactly and refuses a name that is merely a prefix of one', function (string $mailbox, ?string $expected) {
    emcServeDefault();

    if ($expected === null) {
        // "Suppor" is a substring of "Support". A server-side name filter would
        // have matched it; this connector must not.
        expect(emcFailure(fn () => emcConnector(mailbox: $mailbox)->fetchPage(null)))
            ->toBe(ConnectorFailure::Misconfigured);

        return;
    }

    emcConnector(mailbox: $mailbox)->fetchPage(null);

    expect(emcSentArguments('Email/query')['filter']['inMailbox'])->toBe($expected);
})->with([
    'exact' => ['Support', EMC_MAILBOX_ID],
    'a user who typed it in lower case' => ['support', EMC_MAILBOX_ID],
    'surrounding whitespace' => ['  Support  ', EMC_MAILBOX_ID],
    'a different folder' => ['Archive', 'mbx-archive'],
    'a prefix of a real name' => ['Suppor', null],
    'a name no mailbox has' => ['Destek', null],
]);

it('refuses an empty mailbox name before it makes a single request', function () {
    expect(emcFailure(fn () => emcConnector(mailbox: '   ')))->toBe(ConnectorFailure::Misconfigured);
});

it('refuses a session that does not speak the mail capability', function () {
    $session = PlatformFixture::json('email', 'session.json');
    unset($session['capabilities']['urn:ietf:params:jmap:mail']);

    emcServeDefault(['session' => [(string) json_encode($session), 200]]);

    // A valid JMAP server behind the wrong URL is a setting that is wrong, not
    // a credential that is wrong.
    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))->toBe(ConnectorFailure::Misconfigured);
});

/*
|--------------------------------------------------------------------------
| The cold start — one page, and bounded behind now
|--------------------------------------------------------------------------
*/

it('bounds the first query at now minus the configured lookback', function (int $days) {
    CarbonImmutable::setTestNow('2026-09-04T12:00:00Z');
    emcServeDefault();

    emcConnector(lookbackDays: $days)->fetchPage(null);

    $filter = emcSentArguments('Email/query')['filter'];

    // Without this bound a mailbox holding four years of mail spends four years
    // of analysis quota on its first sync. The value is JMAP's UTCDate (RFC
    // 8620 section 1.4): UTC with a literal Z, never a local rendering.
    expect($filter['after'])->toBe(CarbonImmutable::now()->subDays($days)->toIso8601ZuluString())
        ->and($filter['after'])->toEndWith('Z')
        ->and($filter['inMailbox'])->toBe(EMC_MAILBOX_ID);
})->with([
    'the default window' => [EMC_LOOKBACK],
    'a short window' => [7],
    'no history at all' => [0],
]);

it('asks for one page newest-first and does not claim there is another', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(null);

    $arguments = emcSentArguments('Email/query');

    expect($arguments['sort'])->toBe([['property' => 'receivedAt', 'isAscending' => false]])
        ->and($arguments['position'])->toBe(0)
        ->and($arguments['limit'])->toBe(EMC_PAGE_SIZE)
        // The cursor carries a server state, not an offset: there is nowhere to
        // put a query position, and the backfill is bounded on purpose.
        ->and($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe(emcState('page-1.json'));
});

it('chains the query into the get with a result reference instead of one request per message', function () {
    emcServeDefault();

    emcConnector()->fetchPage(null);

    /** @var array<string, mixed>|null $body */
    $body = json_decode((string) Http::recorded()->last()[0]->body(), true);

    expect($body['methodCalls'])->toHaveCount(2)
        ->and($body['methodCalls'][1][0])->toBe('Email/get')
        // RFC 8620 section 3.7. This is the whole reason a page is one HTTP
        // request here and would be one per message on Gmail's REST API.
        ->and($body['methodCalls'][1][1]['#ids'])
        ->toBe(['resultOf' => 'q0', 'name' => 'Email/query', 'path' => '/ids'])
        ->and($body['using'])->toBe(['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail']);
});

it('never asks for the recipients', function () {
    emcServeDefault();

    emcConnector()->fetchPage(null);

    /** @var array<string, mixed>|null $body */
    $body = json_decode((string) Http::recorded()->last()[0]->body(), true);
    $properties = $body['methodCalls'][1][1]['properties'];

    // Spec 8 data minimisation, one step earlier than IngestionRunner's
    // masking: these are the company's own staff addresses and they carry no
    // analytical signal, so they are never requested in the first place.
    expect($properties)->not->toContain('to')
        ->and($properties)->not->toContain('cc')
        ->and($properties)->not->toContain('bcc')
        ->and($properties)->toContain('subject')
        ->and($properties)->toContain('mailboxIds');
});

it('never asks for more messages than the session says one get will answer', function () {
    $session = PlatformFixture::json('email', 'session.json');
    $session['capabilities']['urn:ietf:params:jmap:core']['maxObjectsInGet'] = 2;

    emcServeDefault(['session' => [(string) json_encode($session), 200]]);

    emcConnector(pageSize: 50)->fetchPage(null);

    // The query and the get are one request, so asking past the server's own
    // ceiling would truncate the page silently.
    expect(emcSentArguments('Email/query')['limit'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The mapping
|--------------------------------------------------------------------------
*/

it('maps every field of a plain-text message from the fixture', function () {
    emcServeDefault();

    $items = emcConnector()->fetchPage(null)->items;
    $email = emcEmail('page-1.json', 'em-0001');

    $item = collect($items)->firstWhere('externalId', $email['id']);

    expect($item)->not->toBeNull()
        ->and($item->author)->toBe($email['from'][0]['name'])
        ->and($item->body)->toBe($email['subject']."\n\n".$email['bodyValues']['1']['value'])
        // JMAP publishes no canonical web page for a message; downloadUrl
        // serves the raw blob. Null rather than an invented link.
        ->and($item->sourceUrl)->toBeNull()
        ->and($item->publishedAt)->toBe($email['receivedAt'])
        // E-mail carries no rating, and there is no feedbacks.rating column
        // either way — the value would reach the database only in raw_payload.
        ->and($item->rating)->toBeNull()
        ->and($item->rawPayload)->toBe($email);
});

it('joins the subject onto the text because the analyzer only ever sees the body', function () {
    emcServeDefault();

    $items = emcConnector()->fetchPage(null)->items;

    foreach (emcEmails('page-1.json') as $email) {
        $item = collect($items)->firstWhere('externalId', $email['id']);
        $subject = trim((string) $email['subject']);

        expect($item)->not->toBeNull()
            ->and($item->body)->toStartWith($subject)
            // The subject is where the sentiment usually lives on e-mail
            // ("Still no refund"), and dropping it would throw that away.
            ->and($item->body)->not->toBe($subject);
    }
});

it('falls back to the server preview when the message is html only', function () {
    emcServeDefault();

    $items = emcConnector()->fetchPage(null)->items;
    $email = emcEmail('page-1.json', 'em-0002');

    // Guard the premise: this message really is the HTML-only one.
    expect($email['textBody'][0]['type'])->toBe('text/html');

    $item = collect($items)->firstWhere('externalId', $email['id']);

    // `preview` is the server's own plain-text extract. The alternative is a
    // hand-rolled HTML stripper, which is a class of code this tree does not
    // have and does not want.
    expect($item->body)->toBe($email['subject']."\n\n".$email['preview'])
        ->and($item->body)->not->toContain('<b>')
        ->and($item->body)->not->toContain('<html>');
});

it('uses the local part of the address when the sender has no display name', function () {
    emcServeDefault();

    $items = emcConnector()->fetchPage(null)->items;
    $email = emcEmail('page-1.json', 'em-0003');

    expect($email['from'][0]['name'])->toBeNull();

    $item = collect($items)->firstWhere('externalId', $email['id']);

    // Never the whole address: IngestionRunner masks anything matching one to
    // `[email]`, so passing it through would put that literal string in the
    // author column of every row and throw the sender away entirely.
    expect($item->author)->toBe('sender-03')
        ->and($item->author)->not->toContain('@');
});

it('keeps a message whose body is empty but whose subject is not', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(emcCursor(emcState('changes-1.json')));
    $email = emcEmail('changes-2-last.json', 'em-0006');

    // Guard the premise: no text at all, in either the body value or preview.
    expect(trim((string) $email['bodyValues']['1']['value']))->toBe('')
        ->and(trim((string) $email['preview']))->toBe('');

    $item = collect($page->items)->firstWhere('externalId', $email['id']);

    // Skipping happens only when *both* halves are empty. A subject alone is
    // still something the analyzer can read.
    expect($item)->not->toBeNull()
        ->and($item->body)->toBe(trim((string) $email['subject']));
});

it('skips a message with neither a subject nor any text', function () {
    emcServeDefault([
        'Email/changes:'.emcState('changes-1.json') => [
            emcVariant('changes-2-last.json', 'em-0006', 'subject', '   '),
            200,
        ],
    ]);

    $page = emcConnector()->fetchPage(emcCursor(emcState('changes-1.json')));

    $ids = collect($page->items)->pluck('externalId')->sort()->values()->all();

    // A blank row would sit in the inbox and spend a unit of analysis quota on
    // nothing — the same rule Trustpilot applies to a review with no words.
    expect($ids)->toBe(['em-0007']);
});

it('excludes a message that lives outside the watched mailbox', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(emcCursor(emcState('page-1.json')));
    $outsider = emcEmail('changes-1.json', 'em-0005');

    // Guard the premise: Email/changes is account-wide (RFC 8621 section 4.3),
    // so this message really was handed to the connector.
    expect($outsider['mailboxIds'])->not->toHaveKey(EMC_MAILBOX_ID)
        ->and(collect(emcEmails('changes-1.json'))->pluck('id'))->toContain('em-0005');

    $ids = collect($page->items)->pluck('externalId')->all();

    // Without the client-side check a company watching "Support" would ingest
    // its own Sent and Archive folders as customer feedback.
    expect($ids)->toBe(['em-0004']);
});

it('keeps a message that is in the watched mailbox and another one at the same time', function () {
    emcServeDefault();

    $items = emcConnector()->fetchPage(null)->items;
    $email = emcEmail('page-1.json', 'em-0003');

    expect($email['mailboxIds'])->toHaveCount(2)
        ->and(collect($items)->pluck('externalId'))->toContain($email['id']);
});

it('ignores a message whose mailbox membership the server did not report', function () {
    emcServeDefault([
        'Email/query' => [emcVariant('page-1.json', 'em-0001', 'mailboxIds', []), 200],
    ]);

    $ids = collect(emcConnector()->fetchPage(null)->items)->pluck('externalId')->all();

    // An absent membership map means the server said nothing about where the
    // message lives. Ingesting it would be a guess.
    expect($ids)->not->toContain('em-0001')
        ->and($ids)->toHaveCount(count(emcEmails('page-1.json')) - 1);
});

it('publishes the newest receivedAt on the page as the watermark', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(null);

    $newest = collect(emcEmails('page-1.json'))
        ->sortByDesc(fn (array $email) => SyncCursor::parse($email['receivedAt'])?->getTimestamp())
        ->first()['receivedAt'];

    // Informational only — this connector's position is the server state and
    // nothing here compares against a watermark.
    expect($page->watermark)->toBe($newest);
});

/*
|--------------------------------------------------------------------------
| The cursor — a server state, and only a server state
|--------------------------------------------------------------------------
*/

it('asks what changed since the stored state and carries the new one forward', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(emcCursor(emcState('page-1.json')));
    $changes = emcArgs('changes-1.json', 'Email/changes');

    expect(emcSentArguments('Email/changes')['sinceState'])->toBe(emcState('page-1.json'))
        ->and(emcSentArguments('Email/changes')['maxChanges'])->toBe(EMC_PAGE_SIZE)
        ->and($page->hasMore)->toBe($changes['hasMoreChanges'])
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe($changes['newState']);
});

it('encodes nothing but the token into the cursor', function () {
    emcServeDefault();

    $cursor = emcConnector()->fetchPage(null)->nextCursor;

    // sync_cursor is varchar(255). A watermark this connector never reads would
    // be dead weight in a column with a hard ceiling.
    expect(json_decode((string) $cursor, true))
        ->toBe(['page' => 1, 'token' => emcState('page-1.json')]);
});

it('reports hasMore from hasMoreChanges even when the runner cap would end the run', function () {
    emcServeDefault();

    // A connector that reported hasMore=false on hitting the runner's ceiling
    // would tell IngestionRunner the run completed and let it store a position
    // it never reached (docs/LESSONS.md). The cap is the runner's to apply.
    $page = emcConnector(maxPages: 1)->fetchPage(emcCursor(emcState('page-1.json')));

    expect($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->not->toBeNull();
});

it('does not end the run on an empty change page', function () {
    emcServeDefault();

    $page = emcConnector()->fetchPage(emcCursor(emcState('changes-2-last.json')));
    $changes = emcArgs('changes-none.json', 'Email/changes');

    // Every change on a page may have been an update or a destroy, so an empty
    // `created` list says nothing about the stream. Only hasMoreChanges does.
    expect($page->items)->toBe([])
        ->and($page->hasMore)->toBe($changes['hasMoreChanges'])
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe($changes['newState']);
});

it('starts over from a full query when the server can no longer answer from the stored state', function () {
    emcServeDefault([
        'Email/changes:stale-state' => [emcRaw('changes-cannot-calculate.json'), 200],
        'Email/query' => [emcRaw('page-recovered.json'), 200],
    ]);

    $page = emcConnector()->fetchPage(emcCursor('stale-state'));

    // RFC 8620 section 5.2. Documented, expected and recoverable, so a branch
    // rather than a failure — invariant I2 turns the messages that come back a
    // second time into zero new rows.
    expect(collect($page->items)->pluck('externalId')->all())
        ->toBe(collect(emcEmails('page-recovered.json'))->pluck('id')->all())
        ->and($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->token)->toBe(emcState('page-recovered.json'));
});

it('refuses a change response that does not say whether there is more', function () {
    $changes = PlatformFixture::json('email', 'changes-1.json');
    unset($changes['methodResponses'][0][1]['hasMoreChanges']);

    emcServeDefault([
        'Email/changes:'.emcState('page-1.json') => [(string) json_encode($changes), 200],
    ]);

    // Guessing true loses the rest of the changes; guessing false spins until
    // the runner's cap. Refusing is the only honest answer.
    expect(emcFailure(fn () => emcConnector()->fetchPage(emcCursor(emcState('page-1.json')))))
        ->toBe(ConnectorFailure::MalformedResponse);
});

it('refuses a change response that claims more while handing back the same state', function () {
    $changes = PlatformFixture::json('email', 'changes-1.json');
    $changes['methodResponses'][0][1]['newState'] = emcState('page-1.json');

    emcServeDefault([
        'Email/changes:'.emcState('page-1.json') => [(string) json_encode($changes), 200],
    ]);

    // It would loop until the runner's cap on every single run.
    expect(emcFailure(fn () => emcConnector()->fetchPage(emcCursor(emcState('page-1.json')))))
        ->toBe(ConnectorFailure::MalformedResponse);
});

it('refuses a state too long to survive the sync_cursor column', function () {
    $page = PlatformFixture::json('email', 'page-1.json');
    $page['methodResponses'][1][1]['state'] = str_repeat('s', 201);

    emcServeDefault(['Email/query' => [(string) json_encode($page), 200]]);

    // Storing it would overflow varchar(255) and fail the run with a database
    // error that says nothing useful.
    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))
        ->toBe(ConnectorFailure::MalformedResponse);
});

/*
|--------------------------------------------------------------------------
| Failures — a closed set of sentences, never a response body
|--------------------------------------------------------------------------
*/

it('maps an http status onto the right fixed failure', function (int $status, string $file, ConnectorFailure $expected) {
    emcServeDefault(['Email/query' => [emcRaw($file), $status]]);

    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))->toBe($expected);
})->with([
    'rejected token' => [401, 'error-unauthorized.json', ConnectorFailure::InvalidCredentials],
    'token cannot read the account' => [403, 'error-forbidden.json', ConnectorFailure::InvalidCredentials],
    'no session resource there' => [404, 'error-not-found.json', ConnectorFailure::Misconfigured],
    'request refused' => [400, 'error-not-request.json', ConnectorFailure::Misconfigured],
    'budget exhausted' => [429, 'error-rate-limited.json', ConnectorFailure::RateLimited],
    'server down' => [503, 'error-unauthorized.json', ConnectorFailure::Unreachable],
]);

it('maps a method-level error onto the right fixed failure', function (string $file, string $phase, ConnectorFailure $expected) {
    $incremental = $phase === 'changes';
    $key = $incremental ? 'Email/changes:'.emcState('page-1.json') : 'Email/query';

    emcServeDefault([$key => [emcRaw($file), 200]]);

    $cursor = $incremental ? emcCursor(emcState('page-1.json')) : null;

    expect(emcFailure(fn () => emcConnector()->fetchPage($cursor)))->toBe($expected);
})->with([
    // RFC 8620 section 3.6.2: only the serverFail family describes a server
    // that might answer differently in a minute.
    'account not visible' => ['error-method-account-not-found.json', 'query', ConnectorFailure::Misconfigured],
    'internal server error' => ['error-method-server-fail.json', 'changes', ConnectorFailure::Unreachable],
]);

it('treats a connection error as unreachable rather than as a bad credential', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))->toBe(ConnectorFailure::Unreachable);
});

it('refuses a response that is not a jmap envelope', function (string $body) {
    emcServeDefault(['Email/query' => [$body, 200]]);

    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))->toBe(ConnectorFailure::MalformedResponse);
})->with([
    'no methodResponses' => ['{"sessionState":"sess-0001"}'],
    'methodResponses is not a list' => ['{"methodResponses":"nope"}'],
    'not json at all' => ['<html>gateway</html>'],
]);

it('refuses a response it cannot read rather than guessing at it', function (string $case, ConnectorFailure $expected) {
    [$key, $body] = emcBroken($case);

    emcServeDefault([$key => [$body, 200]]);

    expect(emcFailure(fn () => emcConnector()->fetchPage(null)))->toBe($expected);
})->with([
    // A JMAP server that answers something else entirely at the session URL.
    ['session is not an object', ConnectorFailure::MalformedResponse],
    // A real server behind the wrong URL: a setting is wrong, not a credential.
    ['session names no mail account', ConnectorFailure::Misconfigured],
    ['the mailbox list is not a list', ConnectorFailure::MalformedResponse],
    ['the query answer is under a different call id', ConnectorFailure::MalformedResponse],
    ['the query answer is a different method', ConnectorFailure::MalformedResponse],
    ['the method name is not a string', ConnectorFailure::MalformedResponse],
    // Without a state there is no incremental position, and carrying on means a
    // full query on every run — the re-scan spec 6.1 forbids.
    ['the get carries no state', ConnectorFailure::MalformedResponse],
    ['the get list is not a list', ConnectorFailure::MalformedResponse],
    ['the account refuses the read', ConnectorFailure::InvalidCredentials],
]);

it('reads past a method response that is not a triple', function () {
    emcServeDefault(['Email/query' => [emcMutated('page-1.json', function (array $body): array {
        array_unshift($body['methodResponses'], ['Core/echo']);

        return $body;
    }), 200]]);

    // The envelope is a list of triples (RFC 8620 section 3.3); anything else in
    // it belongs to a call this connector did not make, so it is stepped over
    // rather than treated as a fault.
    expect(emcConnector()->fetchPage(null)->items)->toHaveCount(3);
});

it('reads past junk in the mailbox list', function () {
    emcServeDefault(['Mailbox/get' => [emcMutated('mailboxes.json', function (array $body): array {
        array_unshift(
            $body['methodResponses'][0][1]['list'],
            'not a mailbox',
            ['id' => '', 'name' => 'Support'],
            ['name' => 'Support'],
        );

        return $body;
    }), 200]]);

    emcConnector()->fetchPage(null);

    // An unusable entry must not shadow the real mailbox — an id-less "Support"
    // ahead of the real one would otherwise resolve to nothing.
    expect(emcSentArguments('Email/query')['filter']['inMailbox'])->toBe(EMC_MAILBOX_ID);
});

it('skips a message the server returned without an id', function () {
    emcServeDefault(['Email/query' => [emcVariant('page-1.json', 'em-0001', 'id', '  '), 200]]);

    // There is nothing to key the row on, so there is no row: the unique index
    // that makes I2 work is (integration_id, external_id).
    expect(emcConnector()->fetchPage(null)->items)->toHaveCount(2);
});

it('falls back to the preview when a body part carries no usable part id', function () {
    emcServeDefault(['Email/query' => [emcMutated('page-1.json', function (array $body): array {
        unset($body['methodResponses'][1][1]['list'][0]['textBody'][0]['partId']);

        return $body;
    }), 200]]);

    $email = emcEmail('page-1.json', 'em-0001');
    $item = collect(emcConnector()->fetchPage(null)->items)->firstWhere('externalId', $email['id']);

    expect($item->body)->toBe($email['subject']."\n\n".$email['preview']);
});

it('leaves the author null when the sender is not usable', function (string $case) {
    emcServeDefault(['Email/query' => [emcMutated('page-1.json', function (array $body) use ($case): array {
        $body['methodResponses'][1][1]['list'][2]['from'] = match ($case) {
            'no from at all' => [],
            'from is not a list of objects' => ['sender-03@example.invalid'],
            'neither a name nor an address' => [['name' => null, 'email' => null]],
        };

        return $body;
    }), 200]]);

    $item = collect(emcConnector()->fetchPage(null)->items)->firstWhere('externalId', 'em-0003');

    // The message is still feedback — only the attribution is missing, and
    // `feedbacks.author` is nullable for exactly that.
    expect($item)->not->toBeNull()
        ->and($item->author)->toBeNull();
})->with([
    'no from at all',
    'from is not a list of objects',
    'neither a name nor an address',
]);

it('never puts anything from a response into the message it throws', function () {
    $leak = 'UPSTREAM-ECHO-'.EMC_TOKEN;

    emcServeDefault([
        'Email/query' => [(string) json_encode(['error' => $leak, 'token' => EMC_TOKEN]), 401],
    ]);

    try {
        emcConnector()->fetchPage(null);
        throw new RuntimeException('Expected a ConnectorException, none was thrown.');
    } catch (ConnectorException $e) {
        expect($e->getMessage())->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
            ->and($e->getSafeMessage())->not->toContain(EMC_TOKEN)
            ->and($e->getSafeMessage())->not->toContain($leak);
    }
});

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/

it('reports healthy when the session answers and the mailbox exists', function () {
    emcServeDefault();

    expect(emcConnector()->healthCheck()->healthy)->toBeTrue();
});

it('reports a mistyped mailbox as misconfigured rather than as no feedback yet', function () {
    emcServeDefault();

    $health = emcConnector(mailbox: 'Suppor')->healthCheck();

    // The one failure mode a user cannot diagnose from the UI: a healthy
    // integration that never finds anything.
    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::Misconfigured)
        ->and($health->message())->toBe(ConnectorFailure::Misconfigured->safeMessage());
});

it('reports a rejected token through the safe message', function () {
    emcServeDefault(['session' => [emcRaw('error-unauthorized.json'), 401]]);

    $health = emcConnector()->healthCheck();

    expect($health->healthy)->toBeFalse()
        ->and($health->failure)->toBe(ConnectorFailure::InvalidCredentials)
        ->and($health->message())->not->toContain(EMC_TOKEN);
});

it('exposes the configured ceilings', function () {
    $limits = emcConnector(maxPages: 7)->limits();

    expect($limits->maxPagesPerRun)->toBe(7)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The fixtures themselves — decision D-06
|--------------------------------------------------------------------------
|
| The provenance README promises no real person's data is in these files. This
| asserts the promise rather than restating it, so a re-recording against a live
| account cannot quietly bring one in.
|
*/

it('keeps every recorded address, sender name and host synthetic', function () {
    $files = array_values(array_filter(
        scandir(dirname(PlatformFixture::path('email', 'session.json'))) ?: [],
        static fn (string $name): bool => str_ends_with($name, '.json'),
    ));

    expect($files)->toHaveCount(15);

    foreach ($files as $file) {
        $raw = emcRaw($file);

        preg_match_all('/[\w.+-]+@[\w.-]+/', $raw, $addresses);

        foreach ($addresses[0] as $address) {
            expect($address)->toEndWith('@example.invalid');
        }

        preg_match_all('#https?://([^/"]+)#', $raw, $hosts);

        foreach ($hosts[1] as $host) {
            expect($host)->toEndWith('example.invalid');
        }
    }

    foreach (['page-1.json', 'changes-1.json', 'changes-2-last.json', 'page-recovered.json'] as $file) {
        foreach (emcEmails($file) as $email) {
            foreach ($email['from'] as $sender) {
                $name = $sender['name'];

                expect($name === null || preg_match('/^sender-\d{2}$/', (string) $name) === 1)
                    ->toBeTrue("Fixture {$file} carries a display name that is not synthetic.");
            }
        }
    }
});
