<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Auth\TokenAbility;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The session / API key boundary
|--------------------------------------------------------------------------
|
| Both are rows in personal_access_tokens. These are the tests that keep one
| screen from revoking the other's credentials.
|
*/

it('keeps device sessions out of the api key list', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $owner->createToken('laptop', TokenAbility::session());
    $owner->createToken('ci-runner', TokenAbility::api());

    $names = actingAs($owner, 'sanctum')
        ->getJson('/api/v1/settings/api-keys')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toBe(['ci-runner']);
});

it('keeps api keys out of the device session list', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $owner->createToken('laptop', TokenAbility::session());
    $owner->createToken('ci-runner', TokenAbility::api());

    $names = actingAs($owner, 'sanctum')
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toBe(['laptop']);
});

it('treats a legacy wildcard token as a session and never as an api key', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    // Sanctum's default before the distinction existed.
    $legacy = $owner->createToken('old-web');

    expect($legacy->accessToken->abilities)->toBe(['*'])
        ->and(TokenAbility::isSession($legacy->accessToken))->toBeTrue()
        ->and(TokenAbility::isApiKey($legacy->accessToken))->toBeFalse();

    $sessions = actingAs($owner, 'sanctum')->getJson('/api/v1/auth/tokens')->assertOk()->json('data.*.name');
    $keys = actingAs($owner, 'sanctum')->getJson('/api/v1/settings/api-keys')->assertOk()->json('data');

    expect($sessions)->toBe(['old-web'])
        ->and($keys)->toBe([]);
});

it('refuses to revoke an api key through the session route', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $key = $owner->createToken('ci-runner', TokenAbility::api())->accessToken;

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/auth/tokens/{$key->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(PersonalAccessToken::query()->whereKey($key->id)->exists())->toBeTrue();
});

it('refuses to revoke a device session through the api key route', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $session = $owner->createToken('laptop', TokenAbility::session())->accessToken;

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/settings/api-keys/{$session->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(PersonalAccessToken::query()->whereKey($session->id)->exists())->toBeTrue();
});

it('mints a session token on login, not a wildcard one', function () {
    [$company, $user] = tenant();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'laptop',
    ])->assertOk();

    expect($user->tokens()->firstOrFail()->abilities)->toBe([TokenAbility::SESSION]);
});

/*
|--------------------------------------------------------------------------
| GET / POST / DELETE /settings/api-keys
|--------------------------------------------------------------------------
*/

it('lists the company api keys to any role and never the hash', function (string $role) {
    [$company, $caller] = tenant($role);
    $owner = User::factory()->for($company)->owner()->create();
    $created = $owner->createToken('ci-runner', TokenAbility::api());

    $response = actingAs($caller, 'sanctum')
        ->getJson('/api/v1/settings/api-keys')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'created_at']]]);

    expect($response->getContent())->not->toContain($created->accessToken->token)
        ->and($response->json('data.0'))->not->toHaveKey('token')
        ->and($response->json('data.0'))->not->toHaveKey('abilities');
})->with([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER]);

it('returns the plaintext key exactly once and stores only its hash', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $created = actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertCreated()
        ->assertJsonPath('api_key.name', 'ci-runner')
        ->assertJsonStructure(['api_key' => ['id', 'name', 'created_at'], 'plain_text_token']);

    $plain = $created->json('plain_text_token');
    $secret = explode('|', $plain)[1];
    $row = PersonalAccessToken::query()->findOrFail($created->json('api_key.id'));

    expect($plain)->toStartWith($row->id.'|')
        ->and($row->token)->toBe(hash('sha256', $secret))
        ->and($row->abilities)->toBe([TokenAbility::API]);

    // The list endpoint can never reproduce it.
    $listed = actingAs($owner, 'sanctum')->getJson('/api/v1/settings/api-keys')->assertOk();

    expect($listed->getContent())->not->toContain($secret);
});

it('refuses minting an api key as a member', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);

    actingAs($member, 'sanctum')
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($member->tokens()->count())->toBe(0);
});

it('lets an admin mint an api key', function () {
    [$company, $admin] = tenant(User::ROLE_ADMIN);

    actingAs($admin, 'sanctum')
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertCreated();
});

it('refuses an api key with no name', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/api-keys', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

it('revokes an api key minted by a teammate', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();
    $key = $admin->createToken('ci-runner', TokenAbility::api())->accessToken;

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/settings/api-keys/{$key->id}")
        ->assertNoContent();

    expect(PersonalAccessToken::query()->whereKey($key->id)->exists())->toBeFalse();
});

it('refuses revoking an api key as a member', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);
    $owner = User::factory()->for($company)->owner()->create();
    $key = $owner->createToken('ci-runner', TokenAbility::api())->accessToken;

    actingAs($member, 'sanctum')
        ->deleteJson("/api/v1/settings/api-keys/{$key->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(PersonalAccessToken::query()->whereKey($key->id)->exists())->toBeTrue();
});

it('answers 404 for another company api key', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->owner()->create();
    $theirs = $stranger->createToken('theirs', TokenAbility::api())->accessToken;

    actingAs($ownerA, 'sanctum')
        ->deleteJson("/api/v1/settings/api-keys/{$theirs->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(PersonalAccessToken::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('never lists another company api key', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->owner()->create();
    $stranger->createToken('theirs', TokenAbility::api());
    $ownerA->createToken('mine', TokenAbility::api());

    $names = actingAs($ownerA, 'sanctum')->getJson('/api/v1/settings/api-keys')->assertOk()->json('data.*.name');

    expect($names)->toBe(['mine']);
});

it('denies another company key as not found at the policy too', function () {
    // The controller query already makes this a 404 by not returning the row.
    // ApiKeyPolicy is the second lock, for any caller that reaches it with a
    // token already loaded — it must deny *as not found*, never as forbidden.
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->owner()->create();
    $theirs = $stranger->createToken('theirs', TokenAbility::api())->accessToken;

    $response = Gate::forUser($ownerA)->inspect('delete', $theirs);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
});

it('audits minting and revoking an api key', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $id = actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertCreated()
        ->json('api_key.id');

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/settings/api-keys/{$id}")
        ->assertNoContent();

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());

    expect($actions)->toContain(AuditAction::ApiKeyCreated->value)
        ->and($actions)->toContain(AuditAction::ApiKeyRevoked->value);
});
