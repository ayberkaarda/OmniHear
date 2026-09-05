<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Auth\RecoveryCodes;
use App\Support\Auth\TokenAbility;
use App\Support\Auth\TokenLifetime;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactorChallenge;
use App\Support\Auth\TwoFactorReplayGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| TOTP two-factor authentication — docs/contracts/w10-two-factor.md
|--------------------------------------------------------------------------
|
| `UserResource` has published `two_factor_enabled` since F2, computed from a
| column nothing could write. These tests are what turns that field from a
| decoration into a claim.
|
| The clock is pinned in every test that touches a code, for two reasons: the
| codes are a function of it, and the replay guard spends a *timestep* — so a
| test that does two things in the same 30-second window has to say which step
| each of them is in, rather than hoping the wall clock cooperated.
|
*/

const TWO_FACTOR_PASSWORD = 'correct-horse-battery';

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A user midway through enrolment: secret stored, not yet confirmed.
 *
 * @return array{0: Company, 1: User, 2: string} company, user, base32 secret
 */
function pendingTwoFactorUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create([
        'email' => 'ada@acme-analytics.com',
        'password' => TWO_FACTOR_PASSWORD,
        'role' => User::ROLE_OWNER,
    ]);

    $secret = Totp::generateSecret();
    $user->forceFill(['two_factor_secret' => $secret])->save();

    return [$company, $user->refresh(), $secret];
}

/**
 * A user with a confirmed second factor and a stored set of recovery codes.
 *
 * @return array{0: Company, 1: User, 2: string, 3: list<string>}
 */
function twoFactorUser(): array
{
    [$company, $user, $secret] = pendingTwoFactorUser();

    $codes = app(RecoveryCodes::class)->generate();
    app(RecoveryCodes::class)->store($user, $codes);

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return [$company, $user->refresh(), $secret, $codes];
}

function codeNow(string $secret): string
{
    return Totp::codeAt($secret, Carbon::now()->getTimestamp());
}

/**
 * The audit rows of a company, without needing a tenant context.
 *
 * @return list<string>
 */
function twoFactorAuditActions(Company $company): array
{
    // tenant-scope: bypass-ok the assertion is about which tenant the row was
    // filed under, so the scope that would answer the question is the one under
    // test.
    return AuditLog::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();
}

function twoFactorAuditRow(Company $company, AuditAction $action): AuditLog
{
    // tenant-scope: bypass-ok see twoFactorAuditActions above.
    return AuditLog::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('action', $action->value)
        ->orderBy('id')
        ->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| POST /auth/two-factor — enrolment
|--------------------------------------------------------------------------
*/

it('returns a secret, a provisioning uri and a server-rendered qr code', function () {
    [$company, $user] = tenant();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => 'password'])
        ->assertCreated()
        ->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg_data_uri']);

    $secret = $response->json('secret');

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('otpauth_url'))->toContain('secret='.$secret)
        ->and($user->refresh()->two_factor_secret)->toBe($secret)
        // Started, not enabled: a secret alone must not gate the next login.
        ->and($user->twoFactorEnabled())->toBeFalse()
        ->and($user->twoFactorPending())->toBeTrue();
});

it('renders the qr as an svg data uri rather than shipping a qr library to the browser', function () {
    [$company, $user] = tenant();

    $uri = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => 'password'])
        ->assertCreated()
        ->json('qr_svg_data_uri');

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), strict: true);

    expect($svg)->toBeString()
        ->and($svg)->toContain('<svg')
        // It has to be a *picture of the URI*, not an empty frame.
        ->and(strlen((string) $svg))->toBeGreaterThan(500);
});

it('encrypts the pending secret at rest', function () {
    [$company, $user] = tenant();

    $secret = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => 'password'])
        ->assertCreated()
        ->json('secret');

    // tenant-scope: bypass-ok asserting the raw column bytes
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toContain($secret);
});

it('replaces an unconfirmed secret when enrolment is started again', function () {
    [$company, $user, $first] = pendingTwoFactorUser();

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => TWO_FACTOR_PASSWORD])
        ->assertCreated()
        ->json('secret');

    expect($second)->not->toBe($first)
        ->and($user->refresh()->two_factor_secret)->toBe($second);
});

it('refuses to start enrolment when a factor is already confirmed', function () {
    [$company, $user, $secret] = twoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => TWO_FACTOR_PASSWORD])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_ALREADY_ENABLED');

    // The working secret is untouched: silently replacing it is how an attacker
    // on a stolen session would migrate the factor to a device they hold.
    expect($user->refresh()->two_factor_secret)->toBe($secret);
});

it('refuses to begin enrolment without the account password', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);

    // A session alone cannot arm a factor: nothing is written, so an attacker
    // on a stolen token cannot enrol their own authenticator and walk off with
    // the recovery codes.
    expect($user->refresh()->two_factor_secret)->toBeNull()
        ->and($user->twoFactorPending())->toBeFalse();
});

it('refuses to begin enrolment with a wrong password', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);

    expect($user->refresh()->two_factor_secret)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| POST /auth/two-factor/confirm
|--------------------------------------------------------------------------
*/

it('confirms enrolment and returns eight single-use recovery codes', function () {
    [$company, $user, $secret] = pendingTwoFactorUser();

    $codes = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($secret)])
        ->assertOk()
        ->json('recovery_codes');

    expect($codes)->toHaveCount(RecoveryCodes::COUNT)
        ->and($codes)->each->toMatch('/^[a-z2-9]{4}-[a-z2-9]{4}$/')
        ->and(array_unique($codes))->toHaveCount(RecoveryCodes::COUNT)
        ->and($user->refresh()->twoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_confirmed_at)->not->toBeNull();
});

it('stores the recovery codes hashed and never returns them again', function () {
    [$company, $user, $secret] = pendingTwoFactorUser();

    $codes = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($secret)])
        ->assertOk()
        ->json('recovery_codes');

    $stored = $user->refresh()->two_factor_recovery_codes;

    expect($stored)->toHaveCount(RecoveryCodes::COUNT)
        ->and($stored)->not->toContain($codes[0])
        ->and(Hash::check($codes[0], $stored[0]))->toBeTrue();

    // tenant-scope: bypass-ok asserting the raw column bytes
    $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

    expect($raw)->not->toContain($codes[0]);

    // Nothing hands them back: /auth/me is the whole of what a client can read
    // about itself.
    $me = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

    expect($me->json())->not->toHaveKey('recovery_codes')
        ->and($me->getContent())->not->toContain($codes[0]);
});

it('refuses to confirm with a wrong code', function () {
    [$company, $user, $secret] = pendingTwoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

    expect($user->refresh()->twoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_recovery_codes)->toBeNull();
});

it('refuses to confirm a factor that is already confirmed', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret, $codes] = twoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($secret)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_ALREADY_ENABLED');

    // Crucially the recovery codes are not reissued: a second confirm that
    // silently minted a fresh set would be a way to obtain working recovery
    // codes from a session alone, which is what the regeneration endpoint
    // deliberately charges a current code for.
    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT)
        ->and(Hash::check($codes[0], $user->two_factor_recovery_codes[0]))->toBeTrue();
});

it('refuses to confirm when no enrolment was started', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_NOT_ENABLED');
});

it('validates the shape of the confirmation body', function () {
    [$company, $user] = pendingTwoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('writes an audit row when the factor is enabled', function () {
    [$company, $user, $secret] = pendingTwoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($secret)])
        ->assertOk();

    $row = twoFactorAuditRow($company, AuditAction::TwoFactorEnabled);

    expect($row->user_id)->toBe($user->id)
        ->and($row->getAttribute('company_id'))->toBe($company->id);
});

/*
|--------------------------------------------------------------------------
| POST /auth/login — the changed half
|--------------------------------------------------------------------------
*/

it('answers a correct password with a challenge token when a factor is confirmed', function () {
    [$company, $user] = twoFactorUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TWO_FACTOR_PASSWORD,
    ])
        // 200, not 401: the SPA maps 401 to UNAUTHENTICATED and tears the
        // session down, which would log the user out of the flow they are
        // entering. The password was correct; this is not a failure.
        ->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonStructure(['two_factor_required', 'challenge_token']);

    // And emphatically not a session.
    expect($response->json())->not->toHaveKey('token')
        ->and($response->json())->not->toHaveKey('user')
        ->and($response->json())->not->toHaveKey('company');

    $token = PersonalAccessToken::findToken($response->json('challenge_token'));

    expect($token->abilities)->toBe([TokenAbility::CHALLENGE])
        ->and($token->name)->toBe(TwoFactorChallenge::TOKEN_NAME)
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and($token->expires_at->lessThanOrEqualTo(now()->addMinutes(TokenLifetime::CHALLENGE_MINUTES)))->toBeTrue();
});

it('does not record a successful login until the second factor is proven', function () {
    [$company, $user] = twoFactorUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TWO_FACTOR_PASSWORD,
    ])->assertOk();

    expect(twoFactorAuditActions($company))->not->toContain(AuditAction::LoginSucceeded->value)
        ->and($user->refresh()->last_login_ip)->toBeNull();
});

it('leaves the login response untouched when no factor is confirmed', function () {
    [$company, $user] = pendingTwoFactorUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TWO_FACTOR_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user', 'company'])
        ->assertJsonMissingPath('two_factor_required');
});

it('still refuses a wrong password before ever mentioning a second factor', function () {
    [$company, $user] = twoFactorUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($user->tokens()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| POST /auth/two-factor/challenge
|--------------------------------------------------------------------------
*/

/**
 * Log in as far as the challenge and hand back the plaintext challenge token.
 */
function challengeTokenFor(User $user): string
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TWO_FACTOR_PASSWORD,
    ])->assertOk()->json('challenge_token');
}

it('completes the login with a correct code, in the same shape as a one-step login', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    $response = $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertOk()
        ->assertJsonStructure(['token', 'user', 'company'])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.two_factor_enabled', true)
        ->assertJsonPath('company.id', $company->id);

    $session = PersonalAccessToken::findToken($response->json('token'));

    expect($session->abilities)->toBe([TokenAbility::SESSION])
        // The challenge credential is spent, not merely expired.
        ->and(PersonalAccessToken::query()->where('name', TwoFactorChallenge::TOKEN_NAME)->exists())->toBeFalse();

    expect(twoFactorAuditActions($company))->toContain(AuditAction::LoginSucceeded->value)
        ->and($user->refresh()->last_login_ip)->not->toBeNull();
});

it('lets the session it hands back reach the api', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    $session = $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertOk()
        ->json('token');

    $this->withToken($session)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses a wrong code without spending the token', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

    // Still usable: one typo must not force the password to be entered again.
    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertOk();
});

it('records a failed challenge in the audit trail', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => '000000'])
        ->assertStatus(422);

    $row = twoFactorAuditRow($company, AuditAction::TwoFactorChallengeFailed);

    // A burst of these on one account is a password already in someone else's
    // hands. The row says that and nothing more: no code, no secret (I5).
    expect($row->user_id)->toBe($user->id)
        ->and($row->getAttribute('company_id'))->toBe($company->id);
});

it('destroys the challenge token once the attempt budget is spent', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    for ($attempt = 0; $attempt < TwoFactorChallenge::MAX_ATTEMPTS; $attempt++) {
        $this->withToken($challenge)
            ->postJson('/api/v1/auth/two-factor/challenge', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');
    }

    expect(PersonalAccessToken::query()->where('name', TwoFactorChallenge::TOKEN_NAME)->exists())->toBeFalse();

    // Even the right code cannot revive it: the password has to be presented
    // again, which is what makes the six-digit space finite for an attacker who
    // already has it.
    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses a code that has already been accepted', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $code = codeNow($secret);

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])
        ->assertOk();

    // Same second, same code, same window — and refused, because accepting the
    // same six digits twice is exactly the replay a second factor exists to
    // stop.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

    // The next step's code is not a replay and still works.
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000 + Totp::PERIOD));

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertOk();
});

it('accepts a recovery code and spends it', function () {
    [$company, $user, $secret, $codes] = twoFactorUser();

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertOk()
        ->assertJsonStructure(['token', 'user', 'company']);

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT - 1);

    // Single use: the same code a second time is just a wrong code.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

    // A different one still works.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $codes[1]])
        ->assertOk();
});

it('accepts a recovery code however the phone capitalised it', function () {
    [$company, $user, $secret, $codes] = twoFactorUser();

    // The person using this route is the person whose authenticator is gone,
    // typing into a mobile keyboard that capitalises the first letter of a text
    // field by default. Rejecting `Abcd-efgh` would spend one of five attempts
    // on a code they are reading off the page correctly, with nothing on screen
    // to explain why.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => ucfirst($codes[0])])
        ->assertOk();

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT - 1);

    // Fully upper-cased, and with the stray whitespace a paste picks up.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => '  '.strtoupper($codes[1]).' '])
        ->assertOk();

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT - 2);
});

it('does not accept anything extra by lowercasing the input', function () {
    [$company, $user, $secret, $codes] = twoFactorUser();
    $service = app(RecoveryCodes::class);

    // The alphabet has no uppercase letter, so folding case cannot collide two
    // distinct codes — the normalisation is lossless, not lenient. Nothing that
    // should fail starts passing: a wrong code stays wrong in either case, and
    // the separator and the digits are untouched by strtolower.
    expect($service->consume($user, strtoupper('zzzz-zzzz')))->toBeFalse()
        ->and($service->consume($user, str_replace('-', '', $codes[0])))->toBeFalse()
        ->and($service->consume($user, strtoupper(str_replace('-', '', $codes[0]))))->toBeFalse()
        ->and($service->consume($user, substr($codes[0], 0, -1)))->toBeFalse()
        ->and($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT);

    // And the real code, upper-cased, still is the real code.
    expect($service->consume($user, strtoupper($codes[0])))->toBeTrue()
        ->and($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT - 1);
});

it('refuses an unknown recovery code', function () {
    [$company, $user] = twoFactorUser();

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => 'zzzz-zzzz'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');
});

it('requires exactly one of a code and a recovery code', function (array $body) {
    [$company, $user] = twoFactorUser();

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
})->with([
    'neither' => [[]],
    'both' => [['code' => '123456', 'recovery_code' => 'abcd-efgh']],
]);

it('refuses a challenge with no credential at all', function () {
    [$company, $user] = twoFactorUser();

    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => '123456'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses a challenge token that has outlived its five minutes', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000)->addMinutes(6));

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

/*
|--------------------------------------------------------------------------
| DELETE /auth/two-factor
|--------------------------------------------------------------------------
*/

it('disables the factor when both the password and a code are presented', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/auth/two-factor', [
            'password' => TWO_FACTOR_PASSWORD,
            'code' => codeNow($secret),
        ])
        ->assertNoContent();

    $user->refresh();

    expect($user->twoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();

    $row = twoFactorAuditRow($company, AuditAction::TwoFactorDisabled);

    expect($row->user_id)->toBe($user->id);
});

it('refuses to disable on a session alone', function (array $body, int $status, string $code) {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();

    $body = array_map(
        fn (string $value): string => $value === '<valid-code>' ? codeNow($secret) : $value,
        $body,
    );

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/auth/two-factor', $body)
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    // Still on. Disabling the second factor is the step that turns temporary
    // access into a keepable account, so a stolen session must not be enough.
    expect($user->refresh()->twoFactorEnabled())->toBeTrue();
})->with([
    'no body' => [[], 422, 'VALIDATION_ERROR'],
    'password only' => [['password' => TWO_FACTOR_PASSWORD], 422, 'VALIDATION_ERROR'],
    'code only' => [['code' => '<valid-code>'], 422, 'VALIDATION_ERROR'],
    'wrong password' => [['password' => 'wrong', 'code' => '<valid-code>'], 422, 'VALIDATION_ERROR'],
    'wrong code' => [['password' => TWO_FACTOR_PASSWORD, 'code' => '000000'], 422, 'TWO_FACTOR_CODE_INVALID'],
]);

it('refuses to disable what is not enabled', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/auth/two-factor', [
            'password' => 'password',
            'code' => '123456',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_NOT_ENABLED');
});

it('lets a user re-enrol in the same timestep it disabled in', function () {
    // Everything below happens inside one 30-second step, which is exactly the
    // situation the mark used to break: a user turns the factor off, decides
    // they wanted a different authenticator app, and turns it straight back on.
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = twoFactorUser();
    $guard = app(TwoFactorReplayGuard::class);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/auth/two-factor', [
            'password' => TWO_FACTOR_PASSWORD,
            'code' => codeNow($secret),
        ])
        ->assertNoContent();

    // The step that code belonged to is spent while the factor exists, and the
    // mark goes with the secret it described.
    expect($guard->lastAcceptedStep($user))->toBeNull();

    $fresh = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => TWO_FACTOR_PASSWORD])
        ->assertCreated()
        ->json('secret');

    expect($fresh)->not->toBe($secret);

    // The new secret's first code lands in the very step the old secret's last
    // code was spent in. Without the clearing above this is a 422 and a dead
    // zone of up to 90 seconds that the user cannot interpret: the app says the
    // code is wrong, and the authenticator is showing the right one.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($fresh)])
        ->assertOk();

    expect($user->refresh()->twoFactorEnabled())->toBeTrue()
        ->and($guard->lastAcceptedStep($user))->toBe(Totp::timestep(1700000000));
});

it('forgets the spent-step mark when a new enrolment replaces the secret', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret] = pendingTwoFactorUser();
    $guard = app(TwoFactorReplayGuard::class);

    $guard->spend($user, Totp::timestep(1700000000));

    $fresh = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor', ['password' => TWO_FACTOR_PASSWORD])
        ->assertCreated()
        ->json('secret');

    // A mark left over from a secret that no longer exists is not evidence
    // about the secret that just replaced it.
    expect($guard->lastAcceptedStep($user))->toBeNull();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => codeNow($fresh)])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| POST /auth/two-factor/recovery-codes
|--------------------------------------------------------------------------
*/

it('regenerates the recovery codes and invalidates the old set', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret, $old] = twoFactorUser();

    $fresh = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/recovery-codes', ['code' => codeNow($secret)])
        ->assertOk()
        ->json('recovery_codes');

    expect($fresh)->toHaveCount(RecoveryCodes::COUNT)
        ->and(array_intersect($fresh, $old))->toBe([]);

    // The old list is dead: a leaked printout stops working the moment a new
    // one is issued, which is the only reason to regenerate at all.
    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $old[0]])
        ->assertStatus(422);

    $this->withToken(challengeTokenFor($user))
        ->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $fresh[0]])
        ->assertOk();
});

it('refuses to regenerate without a current code', function () {
    [$company, $user, $secret, $old] = twoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/recovery-codes', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT);
});

it('refuses to regenerate when no factor is confirmed', function () {
    [$company, $user, $secret] = pendingTwoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/recovery-codes', ['code' => codeNow($secret)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_NOT_ENABLED');
});

/*
|--------------------------------------------------------------------------
| The pieces the endpoints lean on
|--------------------------------------------------------------------------
*/

it('never moves the spent-step mark backwards', function () {
    [$company, $user] = twoFactorUser();
    $guard = app(TwoFactorReplayGuard::class);

    expect($guard->lastAcceptedStep($user))->toBeNull();

    expect($guard->spend($user, 100))->toBeTrue();

    // An older code arriving late must not un-spend a newer one, or a replay
    // becomes possible by first replaying something even older. The conditional
    // UPDATE simply matches no row, so the answer is "already used".
    expect($guard->spend($user, 99))->toBeFalse()
        ->and($guard->lastAcceptedStep($user))->toBe(100);

    // The same step twice is the replay itself.
    expect($guard->spend($user, 100))->toBeFalse()
        ->and($guard->lastAcceptedStep($user))->toBe(100);

    expect($guard->spend($user, 101))->toBeTrue()
        ->and($guard->lastAcceptedStep($user))->toBe(101);
});

it('keeps the spent-step mark on the user row, not in the cache', function () {
    [$company, $user] = twoFactorUser();
    $guard = app(TwoFactorReplayGuard::class);

    expect($user->refresh()->two_factor_last_used_step)->toBeNull();

    $guard->spend($user, 4242);

    // The column is the storage, so losing the cache no longer forgets which
    // codes have been spent — the reason the mark left the cache was atomicity,
    // but this is the part a reader can see.
    Cache::flush();

    expect($guard->lastAcceptedStep($user))->toBe(4242)
        ->and($user->refresh()->two_factor_last_used_step)->toBe(4242);

    $guard->clear($user);

    expect($guard->lastAcceptedStep($user))->toBeNull()
        ->and($user->refresh()->two_factor_last_used_step)->toBeNull();
});

it('never publishes the spent-step mark', function () {
    [$company, $user] = twoFactorUser();
    app(TwoFactorReplayGuard::class)->spend($user, 4242);

    // It says when the account last authenticated and nothing a client needs,
    // so it belongs with the secret and the recovery codes in $hidden.
    expect(array_key_exists('two_factor_last_used_step', $user->refresh()->toArray()))->toBeFalse();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonMissingPath('user.two_factor_last_used_step');
});

it('treats an empty or unrecoverable recovery code as no code at all', function () {
    [$company, $user, $secret, $codes] = twoFactorUser();
    $service = app(RecoveryCodes::class);

    expect($service->consume($user, '   '))->toBeFalse()
        ->and($user->refresh()->two_factor_recovery_codes)->toHaveCount(RecoveryCodes::COUNT);

    $user->forceFill(['two_factor_recovery_codes' => null])->save();

    expect($service->consume($user, $codes[0]))->toBeFalse();
});

it('refuses a challenge token older than the sanctum ceiling whatever its own expiry says', function () {
    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    // The shape of a row left behind by a deployment with a different policy:
    // created before the absolute ceiling, expires_at still in the future. The
    // per-token lifetime alone would let it through.
    $user->tokens()->update([
        'created_at' => now()->subMinutes((int) config('sanctum.expiration') + 1),
        'expires_at' => now()->addYear(),
    ]);

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses a challenge for a user whose factor was turned off mid-flow', function () {
    [$company, $user, $secret] = twoFactorUser();
    $challenge = challengeTokenFor($user);

    $user->forceFill([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
        'two_factor_recovery_codes' => null,
    ])->save();

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', ['code' => codeNow($secret)])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    // And the orphaned credential is destroyed rather than left to sit out its
    // five minutes.
    expect(PersonalAccessToken::query()->where('name', TwoFactorChallenge::TOKEN_NAME)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — the secret has exactly one exit
|--------------------------------------------------------------------------
*/

it('never publishes the stored secret on any read surface', function () {
    [$company, $user, $secret] = twoFactorUser();

    foreach (['/api/v1/auth/me', '/api/v1/settings/profile', '/api/v1/settings/team'] as $uri) {
        $response = $this->actingAs($user, 'sanctum')->getJson($uri)->assertOk();

        expect($response->getContent())->not->toContain($secret);
    }
});

it('reports the confirmed factor through the user resource', function () {
    [$company, $user] = twoFactorUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.two_factor_enabled', true);
});
