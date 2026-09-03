<?php

use App\Models\Integration;
use App\Models\User;
use App\Support\Connectors\ConnectorFactory;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| GET /integrations/platforms
|--------------------------------------------------------------------------
|
| The endpoint exists so the integration form stops hand-copying
| config/connectors.php. The test that matters is therefore not "does it
| return appstore" but "does it return exactly what the registry holds" —
| a hard-coded expectation here would recreate the drift it removes.
|
*/

it('publishes exactly the platforms the connector registry accepts', function () {
    [$company, $user] = tenant(User::ROLE_MEMBER);

    $published = actingAs($user, 'sanctum')
        ->getJson('/api/v1/integrations/platforms')
        ->assertOk()
        ->json('data.*.platform');

    expect($published)->toBe(app(ConnectorFactory::class)->platforms());
});

it('publishes the required settings and credentials of every platform', function () {
    [$company, $user] = tenant();
    $connectors = app(ConnectorFactory::class);

    $data = actingAs($user, 'sanctum')
        ->getJson('/api/v1/integrations/platforms')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['platform', 'requires_credentials', 'settings', 'credentials']],
        ])
        ->json('data');

    foreach ($data as $entry) {
        $config = $connectors->config($entry['platform']);

        $requiredSettings = collect($entry['settings'])->where('required', true)->pluck('key')->all();
        $optionalSettings = collect($entry['settings'])->where('required', false)->pluck('key')->all();

        expect($requiredSettings)->toBe($config['required_settings'] ?? [])
            ->and($optionalSettings)->toBe($config['optional_settings'] ?? [])
            ->and(collect($entry['credentials'])->pluck('key')->all())->toBe($config['required_credentials'] ?? [])
            ->and($entry['requires_credentials'])->toBe(($config['required_credentials'] ?? []) !== []);
    }
});

it('publishes the format of a setting the server actually constrains', function () {
    [$company, $user] = tenant();

    $data = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk()->json('data');
    $byPlatform = collect($data)->keyBy('platform');

    $subdomain = collect($byPlatform['zendesk']['settings'])->firstWhere('key', 'subdomain');
    $appId = collect($byPlatform['appstore']['settings'])->firstWhere('key', 'app_id');

    expect($subdomain['format'])->toBe('hostname')
        // Null rather than an invented constraint: the server validates app_id
        // as a plain string, and advertising more than that would be the same
        // drift this endpoint removes, only in the other direction.
        ->and($appId['format'])->toBeNull();
});

it('never publishes a credential value', function () {
    [$company, $user] = tenant();

    $body = actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();

    foreach ($body->json('data.*.credentials') as $credentials) {
        foreach ($credentials as $credential) {
            expect(array_keys($credential))->toBe(['key', 'required']);
        }
    }
});

it('is reachable by every role but not without authentication', function () {
    $this->getJson('/api/v1/integrations/platforms')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    foreach ([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER] as $role) {
        [$company, $user] = tenant($role);
        actingAs($user, 'sanctum')->getJson('/api/v1/integrations/platforms')->assertOk();
    }
});

it('does not shadow the integration show route', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    actingAs($user, 'sanctum')
        ->getJson("/api/v1/integrations/{$integration->id}")
        ->assertOk()
        ->assertJsonPath('integration.id', $integration->id);
});
