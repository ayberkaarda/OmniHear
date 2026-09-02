<?php

use App\Models\Company;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| logout + me
|--------------------------------------------------------------------------
*/

it('revokes only the current token on logout', function () {
    [$company, $user] = tenant();

    $current = $user->createToken('web')->plainTextToken;
    $user->createToken('iPhone');

    $this->withHeader('Authorization', "Bearer {$current}")
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPhone']);
});

it('rejects logout without a token', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('rejects a revoked token', function () {
    [$company, $user] = tenant();
    $token = $user->createToken('web')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertNoContent();

    // Test-harness artifact: every request in a test shares one application
    // instance, and RequestGuard memoizes the resolved user. Production gets a
    // fresh container per request; here the guard has to be reset by hand or
    // the second call would reuse the user resolved before the token was gone.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('returns the authenticated user and its company', function () {
    [$company, $user] = tenant(User::ROLE_ADMIN);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.quota_remaining', 200);
});

it('never leaks another tenant company through me', function () {
    [$companyA, $userA] = tenant();
    Company::factory()->create(['name' => 'Other Tenant Inc.']);

    $body = $this->actingAs($userA, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->getContent();

    expect($body)->not->toContain('Other Tenant Inc.');
});

/*
|--------------------------------------------------------------------------
| Response headers
|--------------------------------------------------------------------------
*/

it('reports the remaining quota on an authenticated response', function () {
    $company = Company::factory()->create(['quota_limit' => 200]);
    $company->forceFill(['analyzed_feedback_count' => 12])->save();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertHeader('X-Quota-Remaining', '188')
        ->assertJsonPath('company.quota_remaining', 188);
});

it('floors the remaining quota at zero', function () {
    $company = Company::factory()->create(['quota_limit' => 10]);
    $company->forceFill(['analyzed_feedback_count' => 25])->save();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertHeader('X-Quota-Remaining', '0');
});

it('echoes a supplied correlation id', function () {
    $this->withHeader('X-Correlation-Id', 'abc-123')
        ->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-Correlation-Id', 'abc-123');
});

it('generates a correlation id when none is supplied', function () {
    $response = $this->getJson('/api/health')->assertOk();

    expect($response->headers->get('X-Correlation-Id'))->not->toBeEmpty();
});

it('replaces an absurdly long correlation id', function () {
    $response = $this->withHeader('X-Correlation-Id', str_repeat('x', 500))
        ->getJson('/api/health')
        ->assertOk();

    expect(strlen((string) $response->headers->get('X-Correlation-Id')))->toBeLessThan(129);
});

it('stamps a correlation id on an error response too', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertHeader('X-Correlation-Id');
});

it('throttles the authenticated surface at 120 requests per minute', function () {
    [$company, $user] = tenant();

    for ($attempt = 1; $attempt <= 120; $attempt++) {
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
})->group('slow');
