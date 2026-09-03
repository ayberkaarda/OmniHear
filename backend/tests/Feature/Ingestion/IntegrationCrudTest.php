<?php

use App\Jobs\FetchFeedbackJob;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;
use App\Support\Connectors\IntegrationSyncLock;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Integration CRUD — docs/contracts/wave2-seams.md section 3
|--------------------------------------------------------------------------
*/

function appStoreSettings(): array
{
    return ['app_id' => '999999999', 'country' => 'tr'];
}

function zendeskPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('refuses every integration route without a token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
})->with([
    ['get', '/api/v1/integrations'],
    ['post', '/api/v1/integrations'],
    ['get', '/api/v1/integrations/1'],
    ['patch', '/api/v1/integrations/1'],
    ['delete', '/api/v1/integrations/1'],
    ['post', '/api/v1/integrations/1/sync'],
]);

/*
|--------------------------------------------------------------------------
| index
|--------------------------------------------------------------------------
*/

it('lists only the tenant own integrations with the documented envelope', function () {
    [$company, $user] = tenant();
    Integration::factory()->count(3)->for($company)->create();
    Integration::factory()->count(2)->for(Company::factory()->create())->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonStructure([
            'data' => [['id', 'platform', 'status', 'settings', 'last_synced_at', 'sync_error', 'feedback_count', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('counts the feedback rows belonging to each integration', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();
    Feedback::factory()->count(4)->for($company)->for($integration)->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations')
        ->assertOk()
        ->assertJsonPath('data.0.feedback_count', 4);
});

it('honours per_page within the contract bounds', function (int $requested, int $expected) {
    [$company, $user] = tenant();
    Integration::factory()->count(3)->for($company)->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations?per_page='.$requested)
        ->assertOk()
        ->assertJsonPath('meta.per_page', $expected);
})->with([
    [2, 2],
    [500, 100],
    [0, 1],
]);

it('lets a member read the list', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);
    Integration::factory()->for($company)->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/integrations')->assertOk();
});

/*
|--------------------------------------------------------------------------
| store
|--------------------------------------------------------------------------
*/

it('creates an integration for the acting tenant', function () {
    [$company, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', [
        'platform' => 'appstore',
        'settings' => appStoreSettings(),
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('integration.platform', 'appstore')
        ->assertJsonPath('integration.status', 'active')
        ->assertJsonPath('integration.settings.app_id', '999999999')
        ->assertJsonPath('integration.sync_error', null)
        ->assertJsonPath('integration.last_synced_at', null)
        ->assertJsonPath('integration.feedback_count', 0);

    $created = asTenant($company, fn () => Integration::query()->findOrFail($response->json('integration.id')));

    expect($created->company_id)->toBe($company->id)
        ->and($created->credentials)->toBe(['api_key' => 'super-secret-value']);
});

it('never takes company_id from the request body', function () {
    [$company, $user] = tenant();
    $other = Company::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', [
        'platform' => 'fixture',
        'company_id' => $other->id,
    ]);

    $response->assertCreated();

    $created = asTenant($company, fn () => Integration::query()->findOrFail($response->json('integration.id')));

    expect($created->company_id)->toBe($company->id);
});

it('creates an integration with no settings and no credentials', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', ['platform' => 'fixture'])
        ->assertCreated()
        ->assertJsonPath('integration.settings', []);
});

it('rejects a create it cannot connect', function (array $payload, string $field) {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);
})->with([
    'no platform' => [[], 'platform'],
    'unknown platform' => [['platform' => 'myspace'], 'platform'],
    // email: docs/contracts/backend-core.md section 1 lists it in the platform
    // enum, but config/connectors.php has no entry for it, so
    // Rule::in($connectors->platforms()) refuses it at the same layer
    // 'unknown platform' does above. googleplay used to be this example; it
    // gained a config entry and a required setting, so the same payload now
    // fails on settings.package_name instead of platform — a different
    // validation layer, which stopped proving anything about the platform
    // whitelist this case names. Check config('connectors.platforms') before
    // reusing 'email' or 'social' here.
    'platform with no connector yet' => [['platform' => 'email'], 'platform'],
    // Zendesk is the first platform that needs credentials. Accepting a create
    // without them would produce an integration the scheduler can only fail on,
    // hours later, instead of a 422 the user can act on now.
    'zendesk without credentials' => [[
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
    ], 'credentials'],
    'zendesk without an api token' => [[
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid'],
    ], 'credentials.api_token'],
    'zendesk without an email' => [[
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['api_token' => 'zdtok-abc'],
    ], 'credentials.email'],
    'zendesk without a subdomain' => [[
        'platform' => 'zendesk',
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ], 'settings'],
    // The subdomain is substituted into the host, so a value carrying `/`, `@`
    // or `:` would send the Authorization header somewhere else entirely.
    'zendesk subdomain with a path' => [[
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'evil.test/x'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ], 'settings.subdomain'],
    'zendesk subdomain with userinfo' => [[
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'user@evil.test'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ], 'settings.subdomain'],
    'app store without settings' => [['platform' => 'appstore'], 'settings'],
    'app store without app id' => [['platform' => 'appstore', 'settings' => ['country' => 'tr']], 'settings.app_id'],
    'app store without country' => [['platform' => 'appstore', 'settings' => ['app_id' => '1']], 'settings.country'],
    'settings not an object' => [['platform' => 'fixture', 'settings' => 'nope'], 'settings'],
    'credentials not an object' => [['platform' => 'fixture', 'credentials' => 'nope'], 'credentials'],
    'fixture set escaping the root' => [['platform' => 'fixture', 'settings' => ['fixture_set' => '../appstore']], 'settings.fixture_set'],
]);

it('refuses a member creating an integration', function () {
    [, $member] = tenant(User::ROLE_MEMBER);

    $this->actingAs($member, 'sanctum')->postJson('/api/v1/integrations', ['platform' => 'fixture'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('lets an admin create an integration', function () {
    [, $admin] = tenant(User::ROLE_ADMIN);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/integrations', ['platform' => 'fixture'])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| show
|--------------------------------------------------------------------------
*/

it('shows one integration', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create(['platform' => 'appstore', 'settings' => appStoreSettings()]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations/'.$integration->id)
        ->assertOk()
        ->assertJsonPath('integration.id', $integration->id)
        ->assertJsonPath('integration.settings.country', 'tr');
});

it('answers 404 for an integration that does not exist', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations/999999')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

/*
|--------------------------------------------------------------------------
| Invariant I1 — another tenant's row is 404, never 403
|--------------------------------------------------------------------------
*/

it('answers 404, not 403, for another tenant integration', function (string $method, string $suffix) {
    [, $user] = tenant();
    $ofAnother = Integration::factory()->for(Company::factory()->create())->create();

    $this->actingAs($user, 'sanctum')
        ->json($method, '/api/v1/integrations/'.$ofAnother->id.$suffix, ['status' => 'paused'])
        ->assertStatus(404)
        ->assertExactJson(['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']);
})->with([
    'show' => ['get', ''],
    'update' => ['patch', ''],
    'delete' => ['delete', ''],
    'sync' => ['post', '/sync'],
]);

it('leaves another tenant integration untouched after a cross-tenant delete attempt', function () {
    [, $user] = tenant();
    $ofAnother = Integration::factory()->for($other = Company::factory()->create())->create();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/integrations/'.$ofAnother->id)->assertStatus(404);

    expect(asTenant($other, fn () => Integration::query()->find($ofAnother->id)))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| update
|--------------------------------------------------------------------------
*/

it('updates settings without disturbing the stored credentials', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'platform' => 'appstore',
        'settings' => appStoreSettings(),
        'credentials' => ['api_key' => 'super-secret-value'],
    ]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, [
        'settings' => ['app_id' => '111', 'country' => 'us'],
    ])->assertOk()->assertJsonPath('integration.settings.country', 'us');

    $reloaded = asTenant($company, fn () => Integration::query()->findOrFail($integration->id));

    expect($reloaded->credentials)->toBe(['api_key' => 'super-secret-value']);
});

it('rotates credentials without echoing them back', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'the-old-one'],
    ]);

    $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, [
        'credentials' => ['api_key' => 'the-new-one'],
    ]);

    $response->assertOk();

    expect($response->json('integration'))->not->toHaveKey('credentials')
        ->and($response->getContent())->not->toContain('the-new-one')
        ->and(asTenant($company, fn () => Integration::query()->findOrFail($integration->id))->credentials)
        ->toBe(['api_key' => 'the-new-one']);
});

it('pauses and resumes an integration', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, ['status' => 'paused'])
        ->assertOk()->assertJsonPath('integration.status', 'paused');

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, ['status' => 'active'])
        ->assertOk()->assertJsonPath('integration.status', 'active');
});

it('rejects an update it cannot accept', function (array $payload, string $field) {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'platform' => 'appstore',
        'settings' => appStoreSettings(),
    ]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);
})->with([
    'platform is immutable' => [['platform' => 'fixture'], 'platform'],
    'error is not a user-settable status' => [['status' => 'error'], 'status'],
    'partial settings would drop a required key' => [['settings' => ['country' => 'us']], 'settings.app_id'],
]);

it('refuses a member updating an integration', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, ['status' => 'paused'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

/*
|--------------------------------------------------------------------------
| destroy
|--------------------------------------------------------------------------
*/

it('deletes an integration as the owner', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/integrations/'.$integration->id)
        ->assertNoContent();

    expect(asTenant($company, fn () => Integration::query()->find($integration->id)))->toBeNull();
});

it('refuses anyone but the owner deleting an integration', function (string $role) {
    [$company, $user] = tenant($role);
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/integrations/'.$integration->id)
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([User::ROLE_ADMIN, User::ROLE_MEMBER]);

/*
|--------------------------------------------------------------------------
| sync
|--------------------------------------------------------------------------
*/

it('queues a sync and answers 202', function () {
    Queue::fake();
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(202)
        ->assertJsonStructure(['message']);

    Queue::assertPushed(
        FetchFeedbackJob::class,
        fn (FetchFeedbackJob $job) => $job->companyId === $company->id
            && $job->integrationId === $integration->id,
    );
});

it('answers 409 while a sync is already running', function () {
    Queue::fake();
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(202);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(409)
        ->assertJsonPath('code', 'SYNC_IN_PROGRESS');

    Queue::assertPushed(FetchFeedbackJob::class, 1);
});

it('takes the lock before the job is queued, not when it runs', function () {
    Queue::fake();
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(202);

    // Queue::fake() means nothing has run yet — the lock still has to be held,
    // otherwise a second request would happily queue a duplicate run.
    expect(app(IntegrationSyncLock::class)->isHeld($integration->id))->toBeTrue();
});

it('lets an admin trigger a sync but not a member', function () {
    Queue::fake();
    [$company, $admin] = tenant(User::ROLE_ADMIN);
    $member = User::factory()->for($company)->state(['role' => User::ROLE_MEMBER])->create();
    $integration = Integration::factory()->for($company)->create();

    $this->actingAs($member, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(403);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/integrations/'.$integration->id.'/sync')
        ->assertStatus(202);
});

/*
|--------------------------------------------------------------------------
| Zendesk — the first platform whose create carries a credential
|--------------------------------------------------------------------------
*/

it('creates a zendesk integration and never echoes the token back', function () {
    [$company, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/integrations', zendeskPayload());

    $response->assertCreated()
        ->assertJsonPath('integration.platform', 'zendesk')
        ->assertJsonPath('integration.settings.subdomain', 'example-help');

    expect($response->getContent())->not->toContain('zdtok-abc')
        ->and($response->json('integration'))->not->toHaveKey('credentials');

    $created = asTenant($company, fn () => Integration::query()->findOrFail($response->json('integration.id')));

    // Written, encrypted, and still readable by the connector — hidden, not lost.
    expect($created->credentials)->toBe(['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc']);
});

it('refuses a credential rotation that would drop a key the connector needs', function () {
    // A PATCH replaces the credentials object wholesale, so a partial one would
    // leave the integration unable to authenticate at all.
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/integrations/'.$integration->id, ['credentials' => ['api_token' => 'zdtok-new']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('credentials.email');
});

it('rotates a zendesk token without disturbing the settings', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create([
        'platform' => 'zendesk',
        'settings' => ['subdomain' => 'example-help'],
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-abc'],
    ]);

    $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/integrations/'.$integration->id, [
        'credentials' => ['email' => 'agent@example.invalid', 'api_token' => 'zdtok-new'],
    ]);

    $response->assertOk()->assertJsonPath('integration.settings.subdomain', 'example-help');

    expect($response->getContent())->not->toContain('zdtok-new')
        ->and(asTenant($company, fn () => Integration::query()->findOrFail($integration->id))->credentials)
        ->toBe(['email' => 'agent@example.invalid', 'api_token' => 'zdtok-new']);
});
