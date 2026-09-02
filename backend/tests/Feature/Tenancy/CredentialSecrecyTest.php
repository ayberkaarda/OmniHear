<?php

use App\Models\Company;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Invariant I5 — integration credentials never leave the database in clear
|--------------------------------------------------------------------------
*/

it('never serializes credentials', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    expect($integration->toArray())->not->toHaveKey('credentials')
        ->and($integration->toJson())->not->toContain('super-secret-value')
        ->and(json_encode($integration))->not->toContain('super-secret-value');
});

it('stores credentials encrypted at rest', function () {
    $company = Company::factory()->create();
    Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    // tenant-scope: bypass-ok asserting the raw column bytes, Eloquent would decrypt them
    $raw = DB::table('integrations')->value('credentials');

    expect($raw)->toBeString()
        ->and($raw)->not->toContain('super-secret-value')
        ->and($raw)->not->toContain('api_key');
});

it('decrypts credentials back into an array for the owning tenant', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->credentials)->toBe(['api_key' => 'super-secret-value']);
});

it('keeps credential material out of sync_error', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    // What a connector failure path is allowed to persist: the upstream status,
    // never the credential it authenticated with.
    $integration->forceFill([
        'status' => 'error',
        'sync_error' => 'Upstream rejected the request with HTTP 401.',
    ])->save();

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->sync_error)
        ->not->toContain('super-secret-value')
        ->and($reloaded->sync_error)->not->toContain('api_key')
        ->and($reloaded->status)->toBe('error');
});

it('hides credentials from an API response built out of the model', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    testApiRoute('get', '_probe/integration-payload', fn () => response()->json($integration));

    $response = $this->getJson('/api/v1/_probe/integration-payload');

    $response->assertOk();

    expect($response->getContent())->not->toContain('super-secret-value')
        ->and($response->json())->not->toHaveKey('credentials');
});
