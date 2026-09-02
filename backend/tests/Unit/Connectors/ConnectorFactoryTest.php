<?php

use App\Models\Integration;
use App\Support\Connectors\AppStoreConnector;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\FixtureConnector;
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

function unsavedIntegration(string $platform, array $settings = []): Integration
{
    $integration = new Integration;
    $integration->forceFill(['platform' => $platform, 'settings' => $settings]);

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
        unsavedIntegration('appstore', ['app_id' => '324684580', 'country' => 'tr']),
    );

    expect($connector)->toBeInstanceOf(AppStoreConnector::class)
        ->and($connector->limits()->maxPagesPerRun)->toBe(10);
});

it('refuses a platform that has no connector', function (string $platform) {
    expect(factoryFailure(fn () => app(ConnectorFactory::class)->for(unsavedIntegration($platform))))
        ->toBe(ConnectorFailure::Misconfigured);
})->with(['googleplay', 'zendesk', 'trustpilot', 'email', 'social', 'not-a-platform']);

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
    expect(app(ConnectorFactory::class)->platforms())->toBe(['fixture', 'appstore']);
});

it('answers whether a platform is supported', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->supports('appstore'))->toBeTrue()
        ->and($factory->supports('zendesk'))->toBeFalse()
        ->and($factory->config('zendesk'))->toBeNull();
});

it('reads the per-platform throttle and backoff out of config', function () {
    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('appstore'))->toBe(['max_attempts' => 20, 'decay_seconds' => 60])
        ->and($factory->retryAfter('appstore'))->toBe(60)
        ->and($factory->retryAfter('fixture'))->toBe(5);
});

it('falls back to safe throttle defaults for a platform with none configured', function () {
    config(['connectors.platforms.bare' => ['connector' => FixtureConnector::class]]);

    $factory = app(ConnectorFactory::class);

    expect($factory->rateLimit('bare'))->toBe(['max_attempts' => 60, 'decay_seconds' => 60])
        ->and($factory->retryAfter('bare'))->toBe(60);
});
