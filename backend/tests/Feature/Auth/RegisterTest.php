<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'company_name' => 'Acme Inc.',
    ], $overrides);
}

it('registers a company with its first owner', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    $response->assertCreated()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'company_id', 'name', 'email', 'role', 'email_verified_at', 'two_factor_enabled', 'created_at'],
            'company' => ['id', 'name', 'plan', 'analyzed_feedback_count', 'quota_limit', 'quota_remaining', 'created_at'],
        ])
        ->assertJsonPath('user.role', 'owner')
        ->assertJsonPath('user.email', 'ada@acme-analytics.com')
        ->assertJsonPath('user.email_verified_at', null)
        ->assertJsonPath('user.two_factor_enabled', false)
        ->assertJsonPath('company.name', 'Acme Inc.')
        ->assertJsonPath('company.plan', 'free')
        ->assertJsonPath('company.quota_limit', 200)
        ->assertJsonPath('company.quota_remaining', 200);

    expect(Company::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);

    $user = User::query()->firstOrFail();
    expect($user->getAttribute('company_id'))->toBe(Company::query()->value('id'));
});

it('never serializes the sensitive user columns', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    $user = $response->json('user');

    expect($user)->not->toHaveKey('password')
        ->and($user)->not->toHaveKey('remember_token')
        ->and($user)->not->toHaveKey('two_factor_secret')
        ->and($user)->not->toHaveKey('last_login_ip');
});

it('returns a token that authenticates the new owner', function () {
    Notification::fake();

    $token = $this->postJson('/api/v1/auth/register', registrationPayload())->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ada@acme-analytics.com');
});

it('sends the verification mail', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    Notification::assertSentTo(User::query()->firstOrFail(), VerifyEmail::class);
});

it('lowercases and trims the email', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => '  ADA@Acme-Analytics.com ']))
        ->assertCreated()
        ->assertJsonPath('user.email', 'ada@acme-analytics.com');
});

it('rolls the whole registration back when the user cannot be created', function () {
    Notification::fake();

    User::factory()->create(['email' => 'ada@acme-analytics.com']);
    $companiesBefore = Company::query()->count();

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(422);

    expect(Company::query()->count())->toBe($companiesBefore);
});

/*
|--------------------------------------------------------------------------
| Errors
|--------------------------------------------------------------------------
*/

it('rejects a missing payload with the validation envelope', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['code', 'message', 'errors' => ['name', 'email', 'password', 'company_name']]);
});

it('rejects a password shorter than twelve characters', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'password' => 'short1234',
        'password_confirmation' => 'short1234',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);
});

it('rejects an unconfirmed password', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'password_confirmation' => 'something-else-entirely',
    ]))->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
});

it('rejects an email that is already registered', function () {
    User::factory()->create(['email' => 'ada@acme-analytics.com']);

    $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email']]);
});

it('rejects a disposable email domain with its own code', function (string $email) {
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => $email]))
        ->assertStatus(422)
        ->assertExactJson([
            'code' => 'DISPOSABLE_EMAIL',
            'message' => 'Disposable email addresses are not accepted. Use your work address.',
        ]);

    expect(Company::query()->count())->toBe(0);
})->with([
    'ada@mailinator.com',
    'ada@sub.mailinator.com',
    'ada@YOPMAIL.COM',
]);

it('throttles registration to five attempts per hour per ip', function () {
    Notification::fake();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/register', registrationPayload([
            'email' => "ada+{$attempt}@acme-analytics.com",
            'company_name' => "Acme {$attempt}",
        ]))->assertCreated();
    }

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'ada+6@acme-analytics.com']))
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});
