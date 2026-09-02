<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('accepts a reset request for a known address', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@acme-analytics.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@acme-analytics.com'])
        ->assertStatus(202)
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('answers identically for an unknown address', function () {
    Notification::fake();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@acme-analytics.com']);

    User::factory()->create(['email' => 'ada@acme-analytics.com']);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@acme-analytics.com']);

    expect($known->status())->toBe(202)
        ->and($unknown->status())->toBe(202)
        ->and($known->json())->toBe($unknown->json());
});

it('points the reset link at the SPA', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ada@acme-analytics.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@acme-analytics.com'])->assertStatus(202);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, (string) config('app.frontend_url'))
            && str_contains($url, '/auth/reset-password?')
            && str_contains($url, 'token=')
            && str_contains($url, urlencode('ada@acme-analytics.com'));
    });
});

it('rejects a reset request without an address', function () {
    $this->postJson('/api/v1/auth/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email']]);
});

/*
|--------------------------------------------------------------------------
| reset-password
|--------------------------------------------------------------------------
*/

it('resets the password and revokes every token', function () {
    $user = User::factory()->create(['email' => 'ada@acme-analytics.com']);
    $user->createToken('web');
    $user->createToken('iPhone');

    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'ada@acme-analytics.com',
        'password' => 'brand-new-passphrase',
        'password_confirmation' => 'brand-new-passphrase',
    ])
        ->assertOk()
        ->assertJsonStructure(['message']);

    $fresh = User::query()->findOrFail($user->id);

    expect(Hash::check('brand-new-passphrase', $fresh->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('lets the user log in with the new password afterwards', function () {
    $user = User::factory()->create(['email' => 'ada@acme-analytics.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'ada@acme-analytics.com',
        'password' => 'brand-new-passphrase',
        'password_confirmation' => 'brand-new-passphrase',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@acme-analytics.com',
        'password' => 'brand-new-passphrase',
    ])->assertOk();
});

it('surfaces an invalid reset token as a validation error', function () {
    User::factory()->create(['email' => 'ada@acme-analytics.com']);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'ada@acme-analytics.com',
        'password' => 'brand-new-passphrase',
        'password_confirmation' => 'brand-new-passphrase',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email']]);
});

it('surfaces an unknown address as a validation error', function () {
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'nobody@acme-analytics.com',
        'password' => 'brand-new-passphrase',
        'password_confirmation' => 'brand-new-passphrase',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('rejects a weak new password', function () {
    $user = User::factory()->create(['email' => 'ada@acme-analytics.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'ada@acme-analytics.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['password']]);
});
