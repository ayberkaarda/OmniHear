<?php

use App\Events\FeedbackIngested;
use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PlatformFixture;

/*
|--------------------------------------------------------------------------
| KVKK: raw_payload keeps no direct identifier
|--------------------------------------------------------------------------
|
| `body` has been masked since the connectors were written. `raw_payload` was
| not, and it is the column that stores the provider response whole - for
| Zendesk, the entire ticket, `via.source.from.address` included. Nothing
| serializes it (FeedbackResource omits the column) and account erasure
| cascades, so this was never a disclosure; it was retention of an identifier
| the product has no use for, which spec 8 requires to be maskable.
|
| The assertion is a pattern over the whole stored document rather than a named
| field. Checking `via.source.from.address` would pass the day Zendesk moves it
| and the day a second provider puts an address somewhere else entirely.
|
| Helpers are local to this file on purpose. The ones in ZendeskIngestionTest
| are global functions that happen to be loaded by then; depending on that
| would make `--filter` runs of this file fail for a reason that has nothing to
| do with what it tests.
|
*/

const RAW_PII_EMAIL = '/[\w.+-]+@[\w-]+\.[a-z]{2,}/i';

/** @return array{0: Company, 1: Integration} */
function piiZendeskIntegration(): array
{
    $company = Company::factory()->create();

    $integration = Integration::factory()->for($company)->create([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-pii-test'],
        'status' => 'active',
        'sync_cursor' => null,
        'sync_error' => null,
    ]);

    return [$company, $integration];
}

function piiZendeskBody(string $file): string
{
    return PlatformFixture::raw('zendesk', $file);
}

beforeEach(function () {
    RateLimiter::clear('connector:zendesk');
    Event::fake([FeedbackIngested::class]);
});

it('stores no address anywhere in a zendesk raw_payload', function () {
    Http::fake([
        '*cursor=*' => Http::response(piiZendeskBody('page-2-end.json'), 200),
        '*' => Http::response(piiZendeskBody('page-1.json'), 200),
    ]);
    [$company, $integration] = piiZendeskIntegration();

    // The fixture has to actually contain addresses, or this test passes by
    // testing nothing.
    expect(piiZendeskBody('page-1.json'))->toMatch(RAW_PII_EMAIL);

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $rows = asTenant($company, fn () => Feedback::query()->get(['author', 'body', 'raw_payload']));

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(json_encode($row->raw_payload, JSON_UNESCAPED_UNICODE))->not->toMatch(RAW_PII_EMAIL)
            ->and($row->body)->not->toMatch(RAW_PII_EMAIL)
            ->and((string) $row->author)->not->toMatch(RAW_PII_EMAIL);
    }
});

it('redacts the address without mangling the rest of the payload', function () {
    // Masking must stay a redaction: the column is still the thing somebody
    // reads while debugging a mapping, and it still has to be valid JSON.
    Http::fake(['*' => Http::response(piiZendeskBody('page-2-end.json'), 200)]);
    [$company, $integration] = piiZendeskIntegration();

    $ticket = PlatformFixture::json('zendesk', 'page-2-end.json')['tickets'][0];

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', (string) $ticket['id'])
        ->firstOrFail());

    expect($row->raw_payload)->toBeArray()
        ->and($row->raw_payload['id'])->toBe($ticket['id'])
        ->and($row->raw_payload['status'])->toBe($ticket['status'])
        ->and($row->raw_payload['via']['channel'])->toBe($ticket['via']['channel'])
        // The address is gone; the structure that held it is not.
        ->and($row->raw_payload['via']['source']['from']['address'])->toBe('[email]');
});

it('masks an address on a multi-label TLD with no residue', function () {
    // `sirket.com.tr` is a two-label public suffix. The old domain pattern
    // `[\w-]+\.[a-z]{2,}` stopped at the last label, masking to `[email].tr` and
    // leaving the second-level domain on the record. The address must go whole.
    $page = PlatformFixture::json('zendesk', 'page-2-end.json');
    $page['tickets'][0]['via']['source']['from']['name'] = 'ahmet@sirket.com.tr';

    Http::fake(['*' => Http::response((string) json_encode($page), 200)]);
    [$company, $integration] = piiZendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', (string) $page['tickets'][0]['id'])
        ->firstOrFail());

    expect($row->author)->toBe('[email]')
        ->and($row->author)->not->toContain('.tr');
});

it('masks an address that arrives as the author name', function () {
    // Zendesk's `via.source.from.name` falls back to the address when the
    // sender set no display name, and `author` is a listed column.
    $page = PlatformFixture::json('zendesk', 'page-2-end.json');
    $page['tickets'][0]['via']['source']['from']['name'] = 'someone@example.invalid';

    Http::fake(['*' => Http::response((string) json_encode($page), 200)]);
    [$company, $integration] = piiZendeskIntegration();

    FetchFeedbackJob::dispatchSync($company->id, $integration->id);

    $row = asTenant($company, fn () => Feedback::query()
        ->where('external_id', (string) $page['tickets'][0]['id'])
        ->firstOrFail());

    expect($row->author)->toBe('[email]');
});
