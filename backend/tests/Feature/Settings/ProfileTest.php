<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Auth\TokenAbility;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| GET /settings/profile
|--------------------------------------------------------------------------
*/

it('returns the authenticated user', function () {
    [$company, $user] = tenant(User::ROLE_ADMIN);

    actingAs($user, 'sanctum')
        ->getJson('/api/v1/settings/profile')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.company_id', $company->id);
});

it('never serializes a password or a two factor secret', function () {
    [$company, $user] = tenant();
    $user->forceFill(['two_factor_secret' => 'TOTPSECRET'])->save();

    $body = actingAs($user, 'sanctum')->getJson('/api/v1/settings/profile')->assertOk();

    expect($body->json('user'))->not->toHaveKey('password')
        ->and($body->json('user'))->not->toHaveKey('two_factor_secret')
        ->and($body->getContent())->not->toContain('TOTPSECRET');
});

/*
|--------------------------------------------------------------------------
| PATCH /settings/profile
|--------------------------------------------------------------------------
*/

it('updates the name without touching verification', function () {
    Notification::fake();
    [$company, $user] = tenant();
    $verifiedAt = $user->email_verified_at;

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['name' => 'Ada Lovelace'])
        ->assertOk()
        ->assertJsonPath('user.name', 'Ada Lovelace')
        ->assertJsonPath('email_verification_required', false);

    expect($user->fresh()->email_verified_at?->toIso8601String())
        ->toBe($verifiedAt?->toIso8601String());

    Notification::assertNothingSent();
});

it('un-verifies the account when the email changes and says so', function () {
    Notification::fake();
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => 'ada@newcorp.io', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.email', 'ada@newcorp.io')
        ->assertJsonPath('user.email_verified_at', null)
        ->assertJsonPath('email_verification_required', true);

    expect($user->fresh()->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('leaves verification alone when the email is re-sent unchanged', function () {
    Notification::fake();
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => strtoupper($user->email)])
        ->assertOk()
        ->assertJsonPath('email_verification_required', false);

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    Notification::assertNothingSent();
});

it('refuses a disposable address with the registration code', function () {
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => 'someone@mailinator.com', 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'DISPOSABLE_EMAIL');

    expect($user->fresh()->email)->not->toBe('someone@mailinator.com');
});

it('refuses an address another account already holds', function () {
    [$company, $user] = tenant();
    $other = User::factory()->for($company)->create();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => $other->email, 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email']]);
});

it('audits a profile update and separately an email change', function () {
    Notification::fake();
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['name' => 'Ada', 'email' => 'ada@newcorp.io', 'password' => 'password'])
        ->assertOk();

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());

    expect($actions)->toContain(AuditAction::ProfileUpdated->value)
        ->and($actions)->toContain(AuditAction::ProfileEmailChanged->value);
});

/*
|--------------------------------------------------------------------------
| PATCH /settings/profile — the password gate on an email change
|--------------------------------------------------------------------------
|
| Moving the address is a one-step account takeover: the reset link follows the
| mailbox. A stolen session must re-prove the password to do it. A change of
| name is not that, and must not be taxed with a password prompt.
*/

it('lets a name-only change through without asking for a password', function () {
    Notification::fake();
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['name' => 'Ada Lovelace'])
        ->assertOk()
        ->assertJsonPath('user.name', 'Ada Lovelace');

    expect($user->fresh()->name)->toBe('Ada Lovelace');
});

it('refuses an email change that does not carry the account password', function () {
    Notification::fake();
    [$company, $user] = tenant();
    $original = $user->email;

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => 'ada@newcorp.io'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);

    // The address did not move and no re-verification mail went out: a stolen
    // session cannot redirect the reset flow to a mailbox it controls.
    expect($user->fresh()->email)->toBe($original);
    Notification::assertNothingSent();
});

it('refuses an email change carrying a wrong password', function () {
    Notification::fake();
    [$company, $user] = tenant();
    $original = $user->email;

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/profile', ['email' => 'ada@newcorp.io', 'password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);

    expect($user->fresh()->email)->toBe($original);
    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| PATCH /settings/password
|--------------------------------------------------------------------------
*/

it('changes the password and revokes every other token but the caller s', function () {
    [$company, $user] = tenant();

    $current = $user->createToken('web', TokenAbility::session());
    $other = $user->createToken('iPhone', TokenAbility::session());

    $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
        ->patchJson('/api/v1/settings/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-secret-1',
            'password_confirmation' => 'a-much-longer-secret-1',
        ])
        ->assertNoContent();

    expect(Hash::check('a-much-longer-secret-1', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->id])
        ->and($user->tokens()->whereKey($other->accessToken->id)->exists())->toBeFalse();
});

it('refuses a wrong current password as a field level validation error', function () {
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-much-longer-secret-1',
            'password_confirmation' => 'a-much-longer-secret-1',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['current_password']]);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change that is not confirmed', function () {
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-secret-1',
            'password_confirmation' => 'something-else-entirely',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['password']]);
});

it('audits a password change', function () {
    [$company, $user] = tenant();

    actingAs($user, 'sanctum')
        ->patchJson('/api/v1/settings/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-secret-1',
            'password_confirmation' => 'a-much-longer-secret-1',
        ])
        ->assertNoContent();

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());

    expect($actions)->toContain(AuditAction::PasswordChanged->value);
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('refuses the profile surface without authentication', function () {
    $this->getJson('/api/v1/settings/profile')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses the profile surface to an unverified account', function () {
    [$company, $user] = tenant();
    $user->forceFill(['email_verified_at' => null])->save();

    actingAs($user, 'sanctum')
        ->getJson('/api/v1/settings/profile')
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});
