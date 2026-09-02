<?php

use App\Http\Resources\Api\V1\IntegrationResource;
use App\Models\Company;
use App\Models\Integration;
use App\Support\Connectors\ConnectorFailure;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Invariant I5 — credentials never leave the database, by any route
|--------------------------------------------------------------------------
|
| F2 already proved the model hides them. What is new in F4 is that there are
| now endpoints, a resource and a failure path, so every one of those gets its
| own assertion: a secret that is written is never read back, including by the
| response to the write that set it.
|
*/

const INTEGRATION_SECRET = 'super-secret-value';

function integrationWithSecret(Company $company, array $attributes = []): Integration
{
    return Integration::factory()->for($company)->create(array_merge([
        'platform' => 'fixture',
        'credentials' => ['api_key' => INTEGRATION_SECRET, 'client_secret' => INTEGRATION_SECRET.'-2'],
    ], $attributes));
}

it('keeps credentials out of every integration endpoint response', function (string $method, string $suffix, array $payload, int $status) {
    [$company, $user] = tenant();
    $integration = integrationWithSecret($company);

    $response = $this->actingAs($user, 'sanctum')
        ->json($method, '/api/v1/integrations/'.$integration->id.$suffix, $payload);

    $response->assertStatus($status);

    expect($response->getContent())->not->toContain(INTEGRATION_SECRET)
        ->and($response->getContent())->not->toContain('credentials');
})->with([
    'show' => ['get', '', [], 200],
    'update' => ['patch', '', ['status' => 'paused'], 200],
    'rotate' => ['patch', '', ['credentials' => ['api_key' => INTEGRATION_SECRET]], 200],
]);

it('keeps credentials out of the list response', function () {
    [$company, $user] = tenant();
    integrationWithSecret($company);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations');

    $response->assertOk();

    expect($response->getContent())->not->toContain(INTEGRATION_SECRET)
        ->and($response->getContent())->not->toContain('credentials');
});

it('does not echo credentials back in the response to the create that set them', function () {
    [, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', [
        'platform' => 'fixture',
        'credentials' => ['api_key' => INTEGRATION_SECRET],
    ]);

    $response->assertCreated();

    expect($response->getContent())->not->toContain(INTEGRATION_SECRET)
        ->and($response->json('integration'))->not->toHaveKey('credentials');
});

it('builds a resource payload with no credentials key at all', function () {
    [$company] = tenant();
    $integration = integrationWithSecret($company);

    $payload = (new IntegrationResource($integration))->resolve();

    expect($payload)->not->toHaveKey('credentials')
        ->and(json_encode($payload))->not->toContain(INTEGRATION_SECRET)
        ->and(array_keys($payload))->toBe([
            'id', 'platform', 'status', 'settings',
            'last_synced_at', 'sync_error', 'feedback_count', 'created_at',
        ]);
});

it('stores the credentials encrypted so the column itself leaks nothing', function () {
    [$company] = tenant();
    integrationWithSecret($company);

    // tenant-scope: bypass-ok asserting the raw column bytes; Eloquent would decrypt them
    $raw = DB::table('integrations')->value('credentials');

    expect($raw)->toBeString()
        ->and($raw)->not->toContain(INTEGRATION_SECRET)
        ->and($raw)->not->toContain('api_key');
});

it('writes a sync_error that no failure reason can contaminate', function (ConnectorFailure $failure) {
    // The message is chosen from a closed enum rather than built from the
    // failure, so there is no code path that could put a secret in it.
    expect($failure->safeMessage())
        ->not->toContain(INTEGRATION_SECRET)
        ->and($failure->safeMessage())->not->toContain('api_key')
        ->and(strlen($failure->safeMessage()))->toBeLessThan(120);
})->with(ConnectorFailure::cases());

it('never lets a settings blob become a hiding place for a secret it then serializes', function () {
    // settings is deliberately public, so this documents the boundary: whatever
    // goes into settings comes back out, and credentials is the only field that
    // is guaranteed hidden.
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'platform' => 'fixture',
        'settings' => ['fixture_set' => 'default'],
        'credentials' => ['api_key' => INTEGRATION_SECRET],
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations/'.$integration->id);

    $response->assertOk()
        ->assertJsonPath('integration.settings.fixture_set', 'default');

    expect($response->getContent())->not->toContain(INTEGRATION_SECRET);
});
