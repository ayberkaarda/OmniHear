<?php

use App\Models\Integration;
use App\Support\Connectors\AppStoreConnector;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\FixtureConnector;
use App\Support\Connectors\ZendeskConnector;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Resolving a connector from config/connectors.php
|--------------------------------------------------------------------------
|
| Unsaved models throughout: this is about configuration, not about rows, and
| keeping the database out of it keeps the failure modes readable.
|
*/

function unsavedIntegration(string $platform, array $settings = [], array $credentials = []): Integration
{
    $integration = new Integration;
    $integration->forceFill([
        'platform' => $platform,
        'settings' => $settings,
        'credentials' => $credentials,
    ]);

    return $integration;
}

function factoryFailure(callable $call): ?ConnectorFailure
{
    try {
        $call();
    } catch (ConnectorException $e) {
        return $e->failure();
    }

    return null;
}

it('builds the fixture connector', function () {
    expect(app(ConnectorFactory::class)->for(unsavedIntegration('fixture')))
        ->toBeInstanceOf(FixtureConnector::class);
});

it('builds the app store connector from its settings', function () {
    $connector = app(ConnectorFactory::class)->for(
        unsavedIntegration('appstore', ['app_id' => '999999999', 'country' => 'tr']),
    );

    expect($connector)->toBeInstanceOf(AppStoreConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(10);
});

it('refuses a platform that has no connector', function (string $platform) {
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(unsavedIntegration($platform))))
        ->toBe(ConnectorFailure::Misconfigured);
})->with(['googleplay', 'trustpilot', 'email', 'social', 'not-a-platform']);

it('builds the zendesk connector from its settings and credentials', function () {
    $connector = app(ConnectorFactory::class)->for(unsavedIntegration(
        'zendesk',
        ['subdomain' => 'example-help'],
        ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ));

    expect($connector)->toBeInstanceOf(ZendeskConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(20);
});

it('refuses a zendesk integration missing a required credential', function (array $credentials) {
    // Invariant I5 lives on this path, so the failure has to be the fixed
    // Misconfigured sentence: it says a key is missing without saying anything
    // about any value.
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(unsavedIntegration(
        'zendesk',
        ['subdomain' => 'example-help'],
        $credentials,
    ))))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'none' => [[]],
    'no token' => [['email' => 'agent@example.invalid']],
    'no email' => [['api_token' => 'zdtok-abc']],
    'empty token' => [['email' => 'agent@example.invalid', 'api_token' => '']],
    'token is not a string' => [['email' => 'agent@example.invalid', 'api_token' => 12345]],
]);

it('refuses a zendesk subdomain that is not a bare dns label', function (string $subdomain) {
    // It is substituted into the host, so anything carrying `/`, `@`, `:` or a
    // dot could redirect the request — and the Authorization header with it.
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(unsavedIntegration(
        'zendesk',
        ['subdomain' => $subdomain],
        ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ))))->toBe(ConnectorFailure::Misconfigured);
})->with(['evil.test/x', 'user@evil.test', 'host:8080', 'a.b', '-nope', 'nope-', 'a b', '..']);

it('refuses an app store integration missing a required setting', function (array $settings) {
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(unsavedIntegration('appstore', $settings))))
        ->toBe(ConnectorFailure::Misconfigured);
})->with([
    [[]],
    [['app_id' => '1']],
    [['country' => 'tr']],
    [['app_id' => '', 'country' => 'tr']],
]);

it('refuses a fixture set name that could escape the fixture root', function (string $set) {
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(
        unsavedIntegration('fixture', ['fixture_set' => $set]),
    )))->toBe(ConnectorFailure::Misconfigured);
})->with(['../appstore', '..', 'a/b', 'set with spaces', '.']);

it('lists exactly the platforms that have a connector today', function () {
    expect(app(ConnectorFactory::class)->platforms())->toBe(['fixture', 'appstore', 'zendesk']);
});

it('answers whether a platform is supported', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->supports('appstore'))->toBeTrue()
        ->and($factory->supports('zendesk'))->toBeTrue()
        ->and($factory->supports('googleplay'))->toBeFalse()
        ->and($factory->config('googleplay'))->toBeNull();
});

it('reads the per-platform throttle and backoff out of config', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('appstore'))->toBe(['max_attempts' => 20, 'decay_seconds' => 60])
        ->and($factory->rateLimit('zendesk'))->toBe(['max_attempts' => 10, 'decay_seconds' => 60])
        ->and($factory->retryAfter('appstore'))->toBe(60)
        ->and($factory->retryAfter('zendesk'))->toBe(60)
        ->and($factory->retryAfter('fixture'))->toBe(5);
});

it('falls back to safe throttle defaults for a platform with none configured', function () {
    config(['connectors.platforms.bare' => ['connector' => FixtureConnector::class]]);

    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('bare'))->toBe(['max_attempts' => 60, 'decay_seconds' => 60])
        ->and($factory->retryAfter('bare'))->toBe(60);
});
