<?php

use App\Models\User;
use App\Support\EmailVerificationLink;
use Carbon\CarbonInterface;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

/**
 * Pulls the four values out of the link the SPA would receive.
 *
 * @return array{id: int, hash: string, expires: int, signature: string}
 */
function verificationParameters(User $user, ?CarbonInterface $expiresAt = null): array
{
    $url = EmailVerificationLink::forUser($user, $expiresAt ?? now()->addHour());

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return [
        'id' => (int) $query['id'],
        'hash' => (string) $query['hash'],
        'expires' => (int) $query['expires'],
        'signature' => (string) $query['signature'],
    ];
}

it('builds a verification link that points at the SPA', function () {
    $user = User::factory()->unverified()->create();

    $url = EmailVerificationLink::forUser($user, now()->addHour());

    expect($url)->toStartWith((string) config('app.frontend_url').'/auth/verify-email?')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('expires=');
});

it('verifies the address from a signed link', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/email/verify', verificationParameters($user))
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['user' => ['id', 'email', 'email_verified_at']]);

    expect(User::query()->findOrFail($user->id)->hasVerifiedEmail())->toBeTrue();
});

it('is idempotent for an already verified user', function () {
    $user = User::factory()->unverified()->create();
    $parameters = verificationParameters($user);

    $this->postJson('/api/v1/auth/email/verify', $parameters)->assertOk();
    $this->postJson('/api/v1/auth/email/verify', $parameters)->assertOk();

    expect(User::query()->findOrFail($user->id)->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered signature', function () {
    $user = User::factory()->unverified()->create();

    $parameters = verificationParameters($user);
    $parameters['signature'] = str_repeat('0', 64);

    $this->postJson('/api/v1/auth/email/verify', $parameters)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['signature']]);

    expect(User::query()->findOrFail($user->id)->hasVerifiedEmail())->toBeFalse();
});

it('rejects a tampered user id', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $parameters = verificationParameters($user);
    $parameters['id'] = $other->id;

    $this->postJson('/api/v1/auth/email/verify', $parameters)->assertStatus(422);
});

it('rejects an expired link', function () {
    $user = User::factory()->unverified()->create();

    $parameters = verificationParameters($user, now()->addMinute());

    $this->travel(2)->minutes();

    $this->postJson('/api/v1/auth/email/verify', $parameters)->assertStatus(422);
});

it('rejects a link whose hash does not match the address', function () {
    $user = User::factory()->unverified()->create();

    $parameters = verificationParameters($user);
    $user->forceFill(['email' => 'moved@acme-analytics.com'])->save();

    $this->postJson('/api/v1/auth/email/verify', $parameters)->assertStatus(422);
});

it('rejects an incomplete verification payload', function () {
    $this->postJson('/api/v1/auth/email/verify', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['id', 'hash', 'expires', 'signature']]);
});

/*
|--------------------------------------------------------------------------
| resend
|--------------------------------------------------------------------------
*/

it('resends the verification mail to an unverified user', function () {
    Notification::fake();

    [$company, $user] = tenant();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202)
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('sends nothing when the address is already verified', function () {
    Notification::fake();

    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertStatus(202);

    Notification::assertNothingSent();
});

it('requires authentication to resend', function () {
    $this->postJson('/api/v1/auth/email/resend')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('throttles resending to six per hour', function () {
    Notification::fake();

    [$company, $user] = tenant();
    $user->forceFill(['email_verified_at' => null])->save();

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertStatus(202);
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
