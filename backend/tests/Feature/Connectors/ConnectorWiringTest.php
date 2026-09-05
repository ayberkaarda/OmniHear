<?php

use App\Models\Integration;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\EmailConnector;
use App\Support\Connectors\GooglePlayConnector;
use App\Support\Connectors\MastodonConnector;
use App\Support\Connectors\TrustpilotConnector;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| ConnectorFactory / PlatformController wiring for googleplay + trustpilot
| + email + social
|--------------------------------------------------------------------------
|
| GooglePlayConnector, GooglePlayAccessToken, TrustpilotConnector,
| EmailConnector and MastodonConnector already have their own green unit and
| ingestion tests on another workstream (email's:
| tests/Unit/Connectors/EmailConnectorTest.php,
| tests/Feature/Ingestion/EmailIngestionTest.php; social's:
| tests/Unit/Connectors/MastodonConnectorTest.php). Nothing here re-tests
| their sync logic. This file is about the `match` arms in ConnectorFactory,
| the config/connectors.php entries, and the format rules in
| IntegrationSettingFormats — the wiring that turns those classes into
| something a tenant can actually create through the API. A wrong
| constructor-argument name in the factory's match arm is exactly the risk
| this file exists to catch, which is why the positive-path test below
| asserts the concrete connector class rather than just "did not throw".
|
*/

/**
 * An unsaved integration, for exercising ConnectorFactory without a database
 * round trip. Named distinctly from tests/Unit/Connectors/ConnectorFactoryTest.php's
 * own `unsavedIntegration()` helper — both files are pulled into the same Pest
 * run, and top-level function names collide globally in PHP.
 */
function wiringIntegration(string $platform, array $settings = [], array $credentials = []): Integration
{
    $integration = new Integration;
    $integration->forceFill([
        'platform' => $platform,
        'settings' => $settings,
        'credentials' => $credentials,
    ]);

    return $integration;
}

function wiringFactoryFailure(callable $call): ?ConnectorFailure
{
    try {
        $call();
    } catch (ConnectorException $e) {
        return $e->failure();
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| 1. ConnectorFactory::for() resolves the right concrete class
|--------------------------------------------------------------------------
*/

it('builds a GooglePlayConnector for a fully-populated googleplay integration', function () {
    $connector = app(ConnectorFactory::class)->for(wiringIntegration(
        'googleplay',
        ['package_name' => 'com.acme.app'],
        ['client_email' => 'sa@acme.iam.gserviceaccount.com', 'private_key' => 'test-fixture-key-material'],
    ));

    expect($connector)->toBeInstanceOf(GooglePlayConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(10)
        ->and($connector->limits()->maxConsecutiveEmptyPages)->toBe(3);
});

it('builds a TrustpilotConnector for a fully-populated trustpilot integration', function () {
    $connector = app(ConnectorFactory::class)->for(wiringIntegration(
        'trustpilot',
        ['business_unit_id' => 'abcdef0123456789abcdef01'],
        ['api_key' => 'test-fixture-trustpilot-key'],
    ));

    expect($connector)->toBeInstanceOf(TrustpilotConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(20)
        ->and($connector->limits()->maxConsecutiveEmptyPages)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| 2. Every required setting / credential, omitted one at a time
|--------------------------------------------------------------------------
|
| Driven from config('connectors.platforms.<p>') rather than a hard-coded key
| list, so a key added to either platform's config later is covered here
| automatically without anyone remembering to update this file.
|
*/

it('refuses a googleplay or trustpilot integration missing any required setting', function () {
    $factory = app(ConnectorFactory::class);

    foreach (['googleplay', 'trustpilot'] as $platform) {
        $config = $factory->config($platform);
        expect($config)->not->toBeNull();

        $settings = array_fill_keys($config['required_settings'] ?? [], 'placeholder-value');
        $credentials = array_fill_keys($config['required_credentials'] ?? [], 'placeholder-value');

        // Sanity check the fixture below is not vacuous: today's config always
        // has at least one required setting for these two platforms.
        expect($settings)->not->toBe([]);

        foreach (array_keys($settings) as $missing) {
            $partialSettings = $settings;
            unset($partialSettings[$missing]);

            $failure = wiringFactoryFailure(
                fn () => $factory->for(wiringIntegration($platform, $partialSettings, $credentials)),
            );

            expect($failure)->toBe(
                ConnectorFailure::Misconfigured,
                "platform={$platform} missing setting={$missing} should be Misconfigured",
            );
        }
    }
});

it('refuses a googleplay or trustpilot integration missing any required credential', function () {
    $factory = app(ConnectorFactory::class);

    foreach (['googleplay', 'trustpilot'] as $platform) {
        $config = $factory->config($platform);
        expect($config)->not->toBeNull();

        $settings = array_fill_keys($config['required_settings'] ?? [], 'placeholder-value');
        $credentials = array_fill_keys($config['required_credentials'] ?? [], 'placeholder-value');

        // Sanity check the fixture below is not vacuous: both platforms need
        // credentials today, which is the whole point of this test existing.
        expect($credentials)->not->toBe([]);

        foreach (array_keys($credentials) as $missing) {
            $partialCredentials = $credentials;
            unset($partialCredentials[$missing]);

            $failure = wiringFactoryFailure(
                fn () => $factory->for(wiringIntegration($platform, $settings, $partialCredentials)),
            );

            expect($failure)->toBe(
                ConnectorFailure::Misconfigured,
                "platform={$platform} missing credential={$missing} should be Misconfigured",
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| 3. supports() / platforms()
|--------------------------------------------------------------------------
*/

it('recognises googleplay and trustpilot as supported platforms', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->supports('googleplay'))->toBeTrue()
        ->and($factory->supports('trustpilot'))->toBeTrue()
        ->and($factory->platforms())->toContain('googleplay')
        ->and($factory->platforms())->toContain('trustpilot');
});

/*
|--------------------------------------------------------------------------
| 4. GET /api/v1/integrations/platforms
|--------------------------------------------------------------------------
*/

it('publishes googleplay and trustpilot with their settings, credentials and format rules', function () {
    [, $user] = tenant();

    $response = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();
    $data = collect($response->json('data'))->keyBy('platform');

    expect($data->keys()->all())->toContain('googleplay', 'trustpilot');

    $googleplay = $data['googleplay'];
    expect($googleplay['requires_credentials'])->toBeTrue()
        ->and(collect($googleplay['settings'])->pluck('key')->all())->toBe(['package_name'])
        ->and(collect($googleplay['settings'])->firstWhere('key', 'package_name')['format'])->toBe('android_package')
        ->and(collect($googleplay['credentials'])->pluck('key')->all())->toBe(['client_email', 'private_key']);

    $trustpilot = $data['trustpilot'];
    expect($trustpilot['requires_credentials'])->toBeTrue()
        ->and(collect($trustpilot['settings'])->pluck('key')->all())->toBe(['business_unit_id'])
        ->and(collect($trustpilot['settings'])->firstWhere('key', 'business_unit_id')['format'])->toBe('hex24')
        ->and(collect($trustpilot['credentials'])->pluck('key')->all())->toBe(['api_key']);
});

it('never lets a stored googleplay or trustpilot credential value reach the platforms catalogue', function () {
    // Invariant I5. The endpoint is config-driven and never touches an
    // Integration row, so this is also a regression guard: if a future change
    // ever made it read actual integration credentials, this is the test that
    // would catch it, because there is a real secret in the database to leak.
    [$company, $user] = tenant();

    Integration::factory()->for($company)->create([
        'platform' => 'googleplay',
        'settings' => ['package_name' => 'com.acme.app'],
        'credentials' => [
            'client_email' => 'sa@acme.iam.gserviceaccount.com',
            'private_key' => 'test-fixture-supersecretgoogleplaymaterial-not-a-real-key',
        ],
    ]);

    Integration::factory()->for($company)->create([
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => 'abcdef0123456789abcdef01'],
        'credentials' => ['api_key' => 'tp-super-secret-api-key-value'],
    ]);

    $response = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();

    expect($response->getContent())
        ->not->toContain('supersecretgoogleplaymaterial')
        ->and($response->getContent())->not->toContain('sa@acme.iam.gserviceaccount.com')
        ->and($response->getContent())->not->toContain('tp-super-secret-api-key-value');

    foreach ($response->json('data.*.credentials') as $credentials) {
        foreach ($credentials as $credential) {
            expect(array_keys($credential))->toBe(['key', 'required']);
        }
    }
});

/*
|--------------------------------------------------------------------------
| 5. POST /api/v1/integrations — package_name / business_unit_id validation
|--------------------------------------------------------------------------
|
| The point of IntegrationSettingFormats existing at all: turning a sync-time
| Misconfigured failure, discovered hours later by the scheduler, into an
| immediate 422 the user can act on at create time.
|
*/

it('validates googleplay package_name and trustpilot business_unit_id at create time', function (array $payload, ?string $invalidField) {
    [, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', $payload);

    if ($invalidField === null) {
        $response->assertCreated()
            ->assertJsonPath('integration.platform', $payload['platform']);

        return;
    }

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($invalidField);
})->with([
    'googleplay package_name with a path separator' => [[
        'platform' => 'googleplay',
        'settings' => ['package_name' => 'com/acme'],
        'credentials' => ['client_email' => 'sa@acme.iam.gserviceaccount.com', 'private_key' => 'key-material'],
    ], 'settings.package_name'],
    'googleplay package_name with no dot segment' => [[
        'platform' => 'googleplay',
        'settings' => ['package_name' => 'acme'],
        'credentials' => ['client_email' => 'sa@acme.iam.gserviceaccount.com', 'private_key' => 'key-material'],
    ], 'settings.package_name'],
    'googleplay package_name attempting path traversal' => [[
        'platform' => 'googleplay',
        'settings' => ['package_name' => '../etc'],
        'credentials' => ['client_email' => 'sa@acme.iam.gserviceaccount.com', 'private_key' => 'key-material'],
    ], 'settings.package_name'],
    'googleplay well-formed package_name succeeds' => [[
        'platform' => 'googleplay',
        'settings' => ['package_name' => 'com.acme.app'],
        'credentials' => ['client_email' => 'sa@acme.iam.gserviceaccount.com', 'private_key' => 'key-material'],
    ], null],
    'trustpilot business_unit_id too short' => [[
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => 'abc123'],
        'credentials' => ['api_key' => 'tp-key'],
    ], 'settings.business_unit_id'],
    'trustpilot business_unit_id not hexadecimal' => [[
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => 'zzzzzzzzzzzzzzzzzzzzzzzz'],
        'credentials' => ['api_key' => 'tp-key'],
    ], 'settings.business_unit_id'],
    'trustpilot well-formed business_unit_id succeeds' => [[
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => 'abcdef0123456789abcdef01'],
        'credentials' => ['api_key' => 'tp-key'],
    ], null],
]);

it('rejects a googleplay or trustpilot create with no credentials at all', function (array $payload, string $field) {
    // Both platforms are credentialed, same as zendesk in
    // tests/Feature/Ingestion/IntegrationCrudTest.php — accepting a create
    // without them would produce an integration the scheduler can only fail
    // on, hours later, instead of a 422 the user can act on now.
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);
})->with([
    'googleplay without credentials' => [[
        'platform' => 'googleplay',
        'settings' => ['package_name' => 'com.acme.app'],
    ], 'credentials'],
    'trustpilot without credentials' => [[
        'platform' => 'trustpilot',
        'settings' => ['business_unit_id' => 'abcdef0123456789abcdef01'],
    ], 'credentials'],
]);

/*
|--------------------------------------------------------------------------
| 6. rate_limit() / retryAfter()
|--------------------------------------------------------------------------
*/

it('reads the configured throttle and backoff for googleplay and trustpilot', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('googleplay'))->toBe(['max_attempts' => 20, 'decay_seconds' => 60])
        ->and($factory->retryAfter('googleplay'))->toBe(60)
        ->and($factory->rateLimit('trustpilot'))->toBe(['max_attempts' => 30, 'decay_seconds' => 60])
        ->and($factory->retryAfter('trustpilot'))->toBe(60);
});

/*
|--------------------------------------------------------------------------
| 7. email — same wiring, credential-only shape
|--------------------------------------------------------------------------
|
| email differs from googleplay/trustpilot in one structural way worth
| calling out: all three of its keys (session_url, api_token, mailbox) are
| credentials, not settings (docs/contracts/w11-email-connector.md), so there
| is no settings.* entry to validate and required_settings is empty. The test
| shapes below are the credential equivalents of sections 2 and 5 above.
|
*/

it('builds an EmailConnector for a fully-populated email integration', function () {
    $connector = app(ConnectorFactory::class)->for(wiringIntegration(
        'email',
        [],
        [
            'session_url' => 'https://jmap.example.invalid/.well-known/jmap',
            'api_token' => 'jmap-LIVE-abcdefghijklmnopqrstuvwxyz-0123456789',
            'mailbox' => 'Support',
        ],
    ));

    expect($connector)->toBeInstanceOf(EmailConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(20)
        ->and($connector->limits()->maxConsecutiveEmptyPages)->toBe(3);
});

it('refuses an email integration missing any required credential', function () {
    $factory = app(ConnectorFactory::class);
    $config = $factory->config('email');
    expect($config)->not->toBeNull();

    $credentials = [
        'session_url' => 'https://jmap.example.invalid/.well-known/jmap',
        'api_token' => 'placeholder-token',
        'mailbox' => 'Support',
    ];

    // Sanity check the fixture below is not vacuous: today's config always
    // requires all three keys.
    expect(array_keys($credentials))->toBe($config['required_credentials'] ?? []);

    foreach (array_keys($credentials) as $missing) {
        $partialCredentials = $credentials;
        unset($partialCredentials[$missing]);

        $failure = wiringFactoryFailure(
            fn () => $factory->for(wiringIntegration('email', [], $partialCredentials)),
        );

        expect($failure)->toBe(
            ConnectorFailure::Misconfigured,
            "email missing credential={$missing} should be Misconfigured",
        );
    }
});

it('recognises email as a supported platform', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->supports('email'))->toBeTrue()
        ->and($factory->platforms())->toContain('email');
});

it('publishes email with its credentials, no settings, and its format rule', function () {
    [, $user] = tenant();

    $response = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();
    $data = collect($response->json('data'))->keyBy('platform');

    expect($data->keys()->all())->toContain('email');

    $email = $data['email'];
    expect($email['requires_credentials'])->toBeTrue()
        ->and($email['settings'])->toBe([])
        ->and(collect($email['credentials'])->pluck('key')->all())->toBe(['session_url', 'api_token', 'mailbox']);

    // The catalogue never carries a `format` for a credential — invariant I5
    // reasoning applies here too: PlatformController's credential map is
    // {key, required} only, same as googleplay/trustpilot above, so a format
    // rule enforced server-side is not also advertised as a value-shaped hint
    // about a field the response never echoes back.
    foreach ($email['credentials'] as $credential) {
        expect(array_keys($credential))->toBe(['key', 'required']);
    }
});

it('never lets a stored email credential value reach the platforms catalogue', function () {
    [$company, $user] = tenant();

    Integration::factory()->for($company)->create([
        'platform' => 'email',
        'settings' => [],
        'credentials' => [
            'session_url' => 'https://jmap.example.invalid/.well-known/jmap',
            'api_token' => 'test-fixture-supersecretjmaptoken-not-real',
            'mailbox' => 'Support',
        ],
    ]);

    $response = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();

    expect($response->getContent())
        ->not->toContain('supersecretjmaptoken')
        ->and($response->getContent())->not->toContain('jmap.example.invalid');
});

/*
|--------------------------------------------------------------------------
| 8. POST /api/v1/integrations — email credential validation
|--------------------------------------------------------------------------
|
| session_url is the one email key with a structural risk: it is used to
| build every later JMAP request, so a non-https value would otherwise be
| accepted here and only fail once EmailConnector's own scheme check runs
| during a sync, hours later. mailbox's only structural risk is
| whitespace-only, which `required` alone does not catch.
|
*/

it('validates email session_url and mailbox at create time', function (array $payload, ?string $invalidField) {
    [, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', $payload);

    if ($invalidField === null) {
        $response->assertCreated()
            ->assertJsonPath('integration.platform', $payload['platform']);

        return;
    }

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($invalidField);
})->with([
    'email session_url without https' => [[
        'platform' => 'email',
        'credentials' => [
            'session_url' => 'http://jmap.example.invalid/.well-known/jmap',
            'api_token' => 'jmap-token',
            'mailbox' => 'Support',
        ],
    ], 'credentials.session_url'],
    'email session_url that is not a url at all' => [[
        'platform' => 'email',
        'credentials' => [
            'session_url' => 'not-a-url',
            'api_token' => 'jmap-token',
            'mailbox' => 'Support',
        ],
    ], 'credentials.session_url'],
    'email mailbox that is only whitespace' => [[
        'platform' => 'email',
        'credentials' => [
            'session_url' => 'https://jmap.example.invalid/.well-known/jmap',
            'api_token' => 'jmap-token',
            'mailbox' => '   ',
        ],
    ], 'credentials.mailbox'],
    'email well-formed credentials succeed' => [[
        'platform' => 'email',
        'credentials' => [
            'session_url' => 'https://jmap.example.invalid/.well-known/jmap',
            'api_token' => 'jmap-token',
            'mailbox' => 'Support',
        ],
    ], null],
]);

it('rejects an email create with no credentials at all', function () {
    // Same reasoning as zendesk/googleplay/trustpilot: accepting a create
    // without them would produce an integration the scheduler can only fail
    // on, hours later, instead of a 422 the user can act on now.
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', ['platform' => 'email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('credentials');
});

it('reads the configured throttle and backoff for email', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('email'))->toBe(['max_attempts' => 30, 'decay_seconds' => 60])
        ->and($factory->retryAfter('email'))->toBe(60);
});

/*
|--------------------------------------------------------------------------
| 9. social — the second no-credential platform, two settings
|--------------------------------------------------------------------------
|
| social differs from every credentialed platform above in the direction
| appstore already established: no `required_credentials` key at all.
| instance_url and hashtag are both settings, not credentials — neither is a
| secret (docs/contracts/w12-social-connector.md) — so the test shapes below
| mirror sections 1/2/4/5/6, and there is no credential-leak or
| missing-credential test here because there is no credential to leak or
| omit.
|
*/

it('builds a MastodonConnector for a fully-populated social integration', function () {
    $connector = app(ConnectorFactory::class)->for(wiringIntegration(
        'social',
        ['instance_url' => 'https://mastodon.example.invalid', 'hashtag' => 'omnihear'],
    ));

    expect($connector)->toBeInstanceOf(MastodonConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(20)
        ->and($connector->limits()->maxConsecutiveEmptyPages)->toBe(3);
});

it('refuses a social integration missing any required setting', function () {
    $factory = app(ConnectorFactory::class);
    $config = $factory->config('social');
    expect($config)->not->toBeNull();

    $settings = [
        'instance_url' => 'https://mastodon.example.invalid',
        'hashtag' => 'omnihear',
    ];

    // Sanity check the fixture below is not vacuous: today's config always
    // requires both keys.
    expect(array_keys($settings))->toBe($config['required_settings'] ?? []);

    foreach (array_keys($settings) as $missing) {
        $partialSettings = $settings;
        unset($partialSettings[$missing]);

        $failure = wiringFactoryFailure(
            fn () => $factory->for(wiringIntegration('social', $partialSettings)),
        );

        expect($failure)->toBe(
            ConnectorFailure::Misconfigured,
            "social missing setting={$missing} should be Misconfigured",
        );
    }
});

it('recognises social as a supported platform requiring no credentials', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->supports('social'))->toBeTrue()
        ->and($factory->platforms())->toContain('social')
        ->and($factory->config('social')['required_credentials'] ?? [])->toBe([]);
});

it('publishes social with its settings, no credentials, and its format rules', function () {
    [, $user] = tenant();

    $response = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();
    $data = collect($response->json('data'))->keyBy('platform');

    expect($data->keys()->all())->toContain('social');

    $social = $data['social'];
    expect($social['requires_credentials'])->toBeFalse()
        ->and($social['credentials'])->toBe([])
        ->and(collect($social['settings'])->pluck('key')->all())->toBe(['instance_url', 'hashtag'])
        ->and(collect($social['settings'])->firstWhere('key', 'instance_url')['format'])->toBe('https_url')
        ->and(collect($social['settings'])->firstWhere('key', 'hashtag')['format'])->toBe('hashtag');
});

it('validates social instance_url and hashtag at create time', function (array $payload, ?string $invalidField) {
    [, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', $payload);

    if ($invalidField === null) {
        $response->assertCreated()
            ->assertJsonPath('integration.platform', $payload['platform']);

        return;
    }

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($invalidField);
})->with([
    'social instance_url without https' => [[
        'platform' => 'social',
        'settings' => ['instance_url' => 'http://mastodon.example.invalid', 'hashtag' => 'omnihear'],
    ], 'settings.instance_url'],
    'social instance_url that is not a url at all' => [[
        'platform' => 'social',
        'settings' => ['instance_url' => 'not-a-url', 'hashtag' => 'omnihear'],
    ], 'settings.instance_url'],
    'social hashtag with a path separator' => [[
        'platform' => 'social',
        'settings' => ['instance_url' => 'https://mastodon.example.invalid', 'hashtag' => 'a/b'],
    ], 'settings.hashtag'],
    'social hashtag with a leading hash character' => [[
        'platform' => 'social',
        'settings' => ['instance_url' => 'https://mastodon.example.invalid', 'hashtag' => '#omnihear'],
    ], 'settings.hashtag'],
    'social well-formed settings succeed' => [[
        'platform' => 'social',
        'settings' => ['instance_url' => 'https://mastodon.example.invalid', 'hashtag' => 'omnihear'],
    ], null],
]);

it('rejects a social create with no settings at all', function () {
    // Same reasoning as every credentialed platform above, applied to
    // settings instead: accepting a create without instance_url/hashtag
    // would produce an integration the scheduler can only fail on, hours
    // later, instead of a 422 the user can act on now.
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', ['platform' => 'social'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('settings');
});

it('reads the configured throttle and backoff for social', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('social'))->toBe(['max_attempts' => 20, 'decay_seconds' => 60])
        ->and($factory->retryAfter('social'))->toBe(60);
});
