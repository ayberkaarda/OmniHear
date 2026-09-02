<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Spec 8 — session tokens are revocable per device
|--------------------------------------------------------------------------
*/

it('lists the tokens of the authenticated user', function () {
    [$company, $user] = tenant();
    $user->createToken('web');
    $user->createToken('iPhone');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'created_at']]])
        ->assertJsonPath('data.0.name', 'web')
        ->assertJsonPath('data.1.name', 'iPhone')
        ->assertJsonPath('data.0.last_used_at', null);
});

it('never serializes the token hash', function () {
    [$company, $user] = tenant();
    $token = $user->createToken('web');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/tokens')->assertOk();

    expect($response->getContent())->not->toContain($token->accessToken->token)
        ->and($response->getContent())->not->toContain(explode('|', $token->plainTextToken)[1]);

    $row = $response->json('data.0');

    expect($row)->not->toHaveKey('token')
        ->and($row)->not->toHaveKey('abilities')
        ->and($row)->not->toHaveKey('tokenable_id');
});

it('lists only the caller tokens, never a teammate device', function () {
    [$company, $user] = tenant();
    $teammate = User::factory()->for($company)->create();

    $user->createToken('mine');
    $teammate->createToken('theirs');

    $names = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toBe(['mine']);
});

it('requires authentication to list tokens', function () {
    $this->getJson('/api/v1/auth/tokens')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

/*
|--------------------------------------------------------------------------
| Revocation
|--------------------------------------------------------------------------
*/

it('revokes one device and leaves the others alone', function () {
    [$company, $user] = tenant();
    $keep = $user->createToken('web')->accessToken;
    $drop = $user->createToken('iPhone')->accessToken;

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/auth/tokens/{$drop->id}")
        ->assertNoContent();

    expect($user->tokens()->pluck('id')->all())->toBe([$keep->id]);
});

it('answers 404 for a token belonging to another user, and leaves it alive', function () {
    [$company, $user] = tenant();
    $teammate = User::factory()->for($company)->create();
    $theirs = $teammate->createToken('theirs')->accessToken;

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/auth/tokens/{$theirs->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($teammate->tokens()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('answers 404 for a token belonging to another tenant', function () {
    [$companyA, $userA] = tenant();
    [$companyB, $userB] = tenant();
    $theirs = $userB->createToken('other-tenant')->accessToken;

    $this->actingAs($userA, 'sanctum')
        ->deleteJson("/api/v1/auth/tokens/{$theirs->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($userB->tokens()->count())->toBe(1);
});

it('answers 404 for a token id that does not exist', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/auth/tokens/999999')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('allows revoking the current token, which ends the session', function () {
    [$company, $user] = tenant();
    $plain = $user->createToken('web')->plainTextToken;
    $id = $user->tokens()->value('id');

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->deleteJson("/api/v1/auth/tokens/{$id}")
        ->assertNoContent();

    // See the note in SessionTest: one application instance per test means the
    // guard has to be reset by hand before the second call.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/auth/tokens')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('requires authentication to revoke', function () {
    $this->deleteJson('/api/v1/auth/tokens/1')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
