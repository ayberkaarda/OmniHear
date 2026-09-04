<?php

use App\Events\FeedbackIngested;
use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\SyncCursor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| Zendesk, end to end — the first connector that holds a credential
|--------------------------------------------------------------------------
|
| App Store needs no credentials, so until this phase invariant I5 had no real
| code path to guard: nothing was read out of `integrations.credentials` and
| nothing could have leaked. This file is where that changes, so the I5 block
| below is written against a credential that is actually used to make requests,
| and against an upstream that echoes it straight back in its error bodies.
|
| The fixtures are synthesised from Zendesk's documentation — see
| contracts/fixtures/platforms/zendesk/README.md for what is documented and what
| is inferred.
|
*/

const ZD_TOKEN = 'zdtok-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789';
const ZD_EMAIL = 'agent@example.invalid';
const ZD_SUBDOMAIN = 'example-help';

/** @return array{0: Company, 1: Integration} */
function zendeskIntegration(array $attributes = [], ?Company $company = null): array
{
    $company ??= Company::factory()->create();

    $integration = Integration::factory()->for($company)->create(array_merge([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => ZD_SUBDOMAIN],
        'credentials' => ['email' => ZD_EMAIL, 'api_token' => ZD_TOKEN],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ], $attributes));

    return [$company, $integration];
}

function zdBody(string $file): string
{
    return PlatformFixture::raw('zendesk', $file);
}

/**
 * @return list<array<string, mixed>>
 */
function zdTickets(string $file): array
{
    /** @var list<array<string, mixed>> $tickets */
    $tickets = PlatformFixture::json('zendesk', $file)['tickets'];

    return $tickets;
}

/**
 * The external ids a page should produce: every ticket except the deleted ones.
 *
 * @return list<string>
 */
function zdIngestableIds(string ...$files): array
{
    $ids = [];

    foreach ($files as $file) {
        foreach (zdTickets($file) as $ticket) {
            if (($ticket['status'] ?? null) !== 'deleted') {
                $ids[] = (string) $ticket['id'];
            }
        }
    }

    sort($ids);

    return $ids;
}

/**
 * Capture what actually reaches the log, rendered, rather than trusting a spy's
 * arguments: a credential could arrive through the context array and only
 * become visible once Monolog formats it.
 */
function captureLog(Closure $run): string
{
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    $run();

    return collect($handler->getRecords())
        ->map(fn ($record) => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
        ->implode("\n");
}

beforeEach(function () {
    RateLimiter::clear('connector:zendesk');
    Event::fake([FeedbackIngested::class]);
});

/*
|--------------------------------------------------------------------------
| A full run across two pages
|--------------------------------------------------------------------------
*/

it('ingests every ticket of the export and skips the deleted one', function () {
    Http::fake([
        '*cursor=*' => Http::response(zdBody('page-2-end.json'), 200),
        '*' => Http::response(zdBody('page-1.json'), 200),
    ]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($stored)->toBe(zdIngestableIds('page-1.json', 'page-2-end.json'))
        ->and($stored)->not->toBeEmpty();

    Event::assertDispatchedTimes(FeedbackIngested::class, count($stored));
});

it('stores the mapped fields of a ticket', function () {
    Http::fake(['*' => Http::response(zdBody('page-2-end.json'), 200)]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $ticket = zdTickets('page-2-end.json')[0];

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', (string) $ticket['id'])
        ->firstOrFail());

    expect($row->author)->toBe($ticket['via']['source']['from']['name'])
        ->and($row->body)->toBe($ticket['description'])
        ->and($row->source_url)->toBe('https://example-help.zendesk.com/agent/tickets/'.$ticket['id'])
        ->and($row->analysis_status)->toBe(Feedback::STATUS_PENDING)
        ->and($row->published_at?->equalTo(SyncCursor::parse($ticket['created_at'])))->toBeTrue()
        ->and($row->raw_payload['id'])->toBe($ticket['id']);
});

it('masks an email address out of a ticket description on the way in', function () {
    // Spec 8: feedback bodies are personal data. One fixture description carries
    // an address on purpose, so the masking hook is on the Zendesk path too and
    // not only on the App Store one.
    Http::fake(['*' => Http::response(zdBody('page-1.json'), 200)]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $withAddress = collect(zdTickets('page-1.json'))
        ->first(fn (array $t) => str_contains((string) $t['description'], '@'));

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', (string) $withAddress['id'])
        ->firstOrFail());

    expect($withAddress)->not->toBeNull()
        ->and($row->body)->toContain('[email]')
        ->and($row->body)->not->toContain('destek.musteri@example.invalid');
});

/*
|--------------------------------------------------------------------------
| Incremental fetch (spec 6.1) — the second run is not a re-scan
|--------------------------------------------------------------------------
*/

it('stores the platform cursor and continues from it on the next run', function () {
    Http::fake([
        '*cursor=*' => Http::response(zdBody('page-2-end.json'), 200),
        '*' => Http::response(zdBody('page-1.json'), 200),
    ]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));
    $expected = PlatformFixture::json('zendesk', 'page-2-end.json')['after_cursor'];

    expect(SyncCursor::decode($reloaded->sync_cursor)->token)->toBe($expected)
        ->and(strlen((string) $reloaded->sync_cursor))->toBeLessThan(255);

    // Run two: the export is caught up, so it answers one empty page and stops.
    Http::fake(['*' => Http::response(zdBody('page-caught-up.json'), 200)]);
    Event::fake([FeedbackIngested::class]);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(Http::recorded()->count())->toBe(1);

    Http::assertSent(function ($request) use ($expected) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // The proof there is no full re-scan: the second run sends the cursor
        // and never falls back to start_time.
        return ($query['cursor'] ?? null) === $expected && ! isset($query['start_time']);
    });

    Event::assertNotDispatched(FeedbackIngested::class);
});

it('keeps going past an empty page instead of treating it as the end', function () {
    $served = 0;

    Http::fake(function () use (&$served) {
        $served++;

        return Http::response(match ($served) {
            1 => zdBody('page-empty-continues.json'),
            default => zdBody('page-2-end.json'),
        }, 200);
    });
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    // Set comparison, not sequence: zdIngestableIds() returns its ids sorted,
    // so this side is sorted to match. The query carries no orderBy and
    // PostgreSQL is free to return the rows in any order. Do not remove the
    // sort; what is under test is which rows survived the empty page, not the
    // order they arrived in.
    $stored = asTenant($company, fn () => Feedback::query()->pluck('external_id')->all());
    sort($stored);

    expect($served)->toBeGreaterThan(1)
        ->and($stored)->toBe(zdIngestableIds('page-2-end.json'));
});

it('stops at the runners page ceiling when the export never ends', function () {
    // maxPagesPerRun is a runaway-loop ceiling, not a platform limit: this
    // connector always reports hasMore from end_of_stream, so the runner is the
    // only thing that can stop a stream that keeps claiming more.
    $served = 0;

    Http::fake(function () use (&$served) {
        $served++;

        return Http::response(json_encode([
            'tickets' => [[
                'id' => 2000 + $served,
                'status' => 'open',
                'description' => 'ticket '.$served,
                'created_at' => '2026-08-'.str_pad((string) $served, 2, '0', STR_PAD_LEFT).'T00:00:00Z',
            ]],
            'after_cursor' => 'cursor-'.$served,
            'end_of_stream' => false,
        ]), 200);
    });
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $limit = (int) config('connectors.platforms.zendesk.max_pages_per_run');

    expect($served)->toBe($limit)
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe($limit);

    // The run was cut short while the connector still had more, so it must
    // resume from the last cursor it reached rather than restart the export.
    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect(SyncCursor::decode($reloaded->sync_cursor)->token)->toBe('cursor-'.$limit);
});

/*
|--------------------------------------------------------------------------
| Invariant I2 — the same ticket twice is one row
|--------------------------------------------------------------------------
*/

it('creates no duplicate row when the same export page is served twice', function () {
    Http::fake(['*' => Http::response(zdBody('page-2-end.json'), 200)]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    $afterFirst = asTenant($company, fn () => Feedback::query()->count());

    // Rewind the cursor so the export genuinely serves the same tickets again
    // and the unique index, not the cursor, is what stops them.
    asTenant($company, fn () => Integration::query()->findOrFail($integration->id)
        ->forceFill(['sync_cursor' => null])->save());

    Event::fake([FeedbackIngested::class]);
    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    expect(asTenant($company, fn () => Feedback::query()->count()))->toBe($afterFirst)
        ->and($afterFirst)->toBeGreaterThan(0);

    // Re-firing would re-analyse the comment and burn a second unit of quota,
    // which is the whole reason I2 exists.
    Event::assertNotDispatched(FeedbackIngested::class);
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the credential path
|--------------------------------------------------------------------------
*/

it('writes a sync_error that carries no credential material', function (int $status, string $file, string $expected) {
    // The upstream echoes the credential back in its error body — the worst
    // realistic case, and the one a message built from a response would leak.
    Http::fake(['*' => Http::response(
        json_encode(['error' => 'UPSTREAM-ECHO-BODY', 'token' => ZD_TOKEN, 'user' => ZD_EMAIL, 'body' => zdBody($file)]),
        $status
    )]);
    [$company, $integration] = zendeskIntegration();

    try {
        (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
    } catch (Throwable) {
        // Transient failures are rethrown for the queue. The recorded state is
        // what this test is about.
    }

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)->toBe($expected)
        ->and($reloaded->sync_error)->not->toContain(ZD_TOKEN)
        ->and($reloaded->sync_error)->not->toContain(ZD_EMAIL)
        ->and($reloaded->sync_error)->not->toContain('UPSTREAM-ECHO-BODY')
        ->and($reloaded->status)->toBe('error');
})->with([
    'rejected credentials' => [401, 'error-unauthorized.json', 'The platform rejected the integration credentials.'],
    'no export permission' => [403, 'error-forbidden.json', 'The platform rejected the integration credentials.'],
    'upstream down' => [500, 'error-unauthorized.json', 'The platform could not be reached.'],
]);

it('logs nothing that contains the credential, on the failure path or the happy one', function () {
    [$company, $integration] = zendeskIntegration();

    $failing = captureLog(function () use ($company, $integration) {
        Http::fake(['*' => Http::response(json_encode(['error' => ZD_TOKEN, 'user' => ZD_EMAIL]), 401)]);

        try {
            (new FetchFeedbackJob($company->id, $integration->id))->handle(app(TenantContext::class));
        } catch (Throwable) {
        }
    });

    $capped = captureLog(function () use ($company, $integration) {
        // The other line the ingestion path writes: the runner's capped-run
        // warning. It has to be provoked, or this test only covers one of them.
        $served = 0;
        Http::fake(function () use (&$served) {
            $served++;

            return Http::response(json_encode([
                'tickets' => [],
                'after_cursor' => 'cursor-'.$served,
                'end_of_stream' => false,
            ]), 200);
        });

        FetchFeedbackJob::dispatchSync($company->id, $integration->id);
    });

    // `invalid_credentials` is the failure *reason*, a fixed enum value, so the
    // bare word appears legitimately. What must never appear is the column, its
    // keys or its values.
    foreach ([$failing, $capped] as $written) {
        expect($written)->not->toBe('')
            ->and($written)->not->toContain(ZD_TOKEN)
            ->and($written)->not->toContain(ZD_EMAIL)
            ->and($written)->not->toContain('api_token')
            ->and($written)->not->toContain('"credentials"')
            ->and($written)->not->toContain('Authorization')
            ->and($written)->not->toContain('Basic ');
    }
});

it('serializes the credential into nothing that leaves the database', function () {
    [$company, $integration] = zendeskIntegration();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    // The queued job payload is the one place a credential could travel without
    // anyone looking: it is written to Redis and to failed_jobs.
    $job = serialize(new FetchFeedbackJob($company->id, $integration->id));

    expect(json_encode($reloaded))->not->toContain(ZD_TOKEN)
        ->and(json_encode($reloaded->toArray()))->not->toContain(ZD_TOKEN)
        ->and($job)->not->toContain(ZD_TOKEN)
        ->and($job)->not->toContain(ZD_EMAIL)
        // The credential is still there — hidden, not lost.
        ->and($reloaded->credentials['api_token'])->toBe(ZD_TOKEN);
});

it('never sends the credential anywhere but the authorization header', function () {
    Http::fake(['*' => Http::response(zdBody('page-caught-up.json'), 200)]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), ZD_TOKEN)
        && ! str_contains($request->url(), ZD_EMAIL)
        && $request->body() === ''
        && $request->header('Authorization') === ['Basic '.base64_encode(ZD_EMAIL.'/token:'.ZD_TOKEN)]);
});

it('refuses to build a connector for an integration missing a credential', function (array $credentials) {
    [$company, $integration] = zendeskIntegration(['credentials' => $credentials]);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('error')
        ->and($reloaded->sync_error)->toBe('The integration settings are incomplete for this platform.');

    Http::assertNothingSent();
})->with([
    'no credentials at all' => [[]],
    'no token' => [['email' => ZD_EMAIL]],
    'no email' => [['api_token' => ZD_TOKEN]],
    'empty token' => [['email' => ZD_EMAIL, 'api_token' => '']],
]);

it('refuses a subdomain that could point the authenticated request somewhere else', function (string $subdomain) {
    // The subdomain is substituted into the host. A value carrying `/`, `@` or
    // `:` would send the Authorization header to a host of the writer's
    // choosing — the credential-exfiltration shape of an SSRF.
    [$company, $integration] = zendeskIntegration(['settings' => ['subdomain' => $subdomain]]);

    expect(fn () => app(ConnectorFactory::class)->for($integration))
        ->toThrow(ConnectorException::class);

    Http::assertNothingSent();
})->with([
    'evil.test/x' => ['evil.test/x'],
    'user@evil.test' => ['user@evil.test'],
    'host:8080' => ['host:8080'],
    'a.b' => ['a.b'],
    'empty' => [''],
    'leading dash' => ['-nope'],
]);

/*
|--------------------------------------------------------------------------
| The rate-limit path
|--------------------------------------------------------------------------
*/

it('backs off on a 429 without marking the integration broken', function () {
    // The platform is throttling us, not rejecting us: nothing is wrong with the
    // integration's configuration, so it must not land in the error state the
    // user is asked to fix.
    Http::fake(['*' => Http::response(zdBody('error-rate-limited.json'), 429)]);
    [$company, $integration] = zendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('active')
        ->and($reloaded->sync_error)->toBeNull()
        ->and($reloaded->sync_cursor)->toBeNull();
});

it('stops calling the platform once its own request budget is spent', function () {
    Http::fake(['*' => Http::response(zdBody('page-caught-up.json'), 200)]);
    [$company, $integration] = zendeskIntegration();

    $limit = (int) config('connectors.platforms.zendesk.rate_limit.max_attempts');

    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit('connector:zendesk', 60);
    }

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    Http::assertNothingSent();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->status)->toBe('active')
        ->and($reloaded->sync_error)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Invariant I1 — another tenant's Zendesk integration is a 404, never a 403
|--------------------------------------------------------------------------
*/

it('answers 404 for another tenants zendesk integration', function (string $method, string $suffix) {
    [$owner] = zendeskIntegration();
    $integration = asTenant($owner, fn () => Integration::query()->firstOrFail());

    [, $outsider] = tenant();

    $this->actingAs($outsider, 'sanctum')
        ->json($method, '/api/v1/integrations/'.$integration->id.$suffix, $method === 'get' ? [] : ['status' => 'paused'])
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
})->with([
    'show' => ['get', ''],
    'update' => ['patch', ''],
    'sync' => ['post', '/sync'],
    'destroy' => ['delete', ''],
]);

it('never lets another tenant see or sync the rows behind a zendesk integration', function () {
    Http::fake(['*' => Http::response(zdBody('page-2-end.json'), 200)]);

    [$owner, $integration] = zendeskIntegration();
    FetchFeedbackJob::dispatchSync($owner->id, $integration->id);

    $ingested = asTenant($owner, fn () => Feedback::query()->count());
    [, $outsider] = tenant();

    $response = $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/feedbacks');

    expect($ingested)->toBeGreaterThan(0)
        ->and($response->json('data'))->toBe([]);

    // And the credential is not reachable from the other side either.
    expect($response->getContent())->not->toContain(ZD_TOKEN);
});
