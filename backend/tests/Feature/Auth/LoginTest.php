<?php

use App\Models\Company;
use App\Models\User;

function loginUser(array $overrides = []): User
{
    $company = Company::factory()->create();

    return User::factory()->for($company)->create(array_merge([
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
        'role' => User::ROLE_OWNER,
    ], $overrides));
}

it('issues a token for valid credentials', function () {
    $user = loginUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user', 'company'])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('company.id', $user->getAttribute('company_id'));
});

it('names the token after the device', function () {
    $user = loginUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
        'device_name' => 'iPhone 15',
    ])->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPhone 15']);
});

it('defaults the token name to web', function () {
    $user = loginUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['web']);
});

it('records the login ip without exposing it', function () {
    $user = loginUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();

    expect(User::query()->findOrFail($user->id)->getAttribute('last_login_ip'))->not->toBeNull()
        ->and($response->json('user'))->not->toHaveKey('last_login_ip');
});

it('lets an unverified user in so the SPA can show the inbox prompt', function () {
    loginUser(['email_verified_at' => null]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertOk()
        ->assertJsonPath('user.email_verified_at', null);
});

it('accepts a differently cased email', function () {
    loginUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ADA@Acme-Analytics.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Errors
|--------------------------------------------------------------------------
*/

it('answers the same code for a wrong password and for an unknown address', function () {
    loginUser();

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'not-the-right-password',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ]);

    $wrongPassword->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $unknownEmail->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($wrongPassword->json())->toBe($unknownEmail->json());
});

it('rejects a malformed login payload', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email', 'password']]);
});

it('throttles login to ten attempts per minute per ip', function () {
    loginUser();

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@acme-analytics.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
