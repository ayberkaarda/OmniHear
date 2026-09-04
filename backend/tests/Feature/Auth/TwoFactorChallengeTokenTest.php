<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Auth\TokenAbility;
use App\Support\Auth\TokenLifetime;
use App\Support\Auth\Totp;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| What a challenge token may do — and it is one thing
|--------------------------------------------------------------------------
|
| Every request in this file carries a REAL bearer token in a REAL
| Authorization header. Never `actingAs($user, 'sanctum')`: that installs a
| TransientToken, so `currentAccessToken()` is not a PersonalAccessToken at all
| and EnforceTokenAbility returns before it asks a single question. A suite
| written that way would exercise the routes and never the credential — the
| ability branch could be deleted outright and nothing would go red.
|
| docs/LESSONS.md 2026-09-03 records the last time exactly that happened, to
| this same middleware. This file is the answer to it for the third ability.
|
| The stake is concrete. A challenge token is minted from a *password alone*.
| If it were accepted anywhere but the challenge endpoint, then two-factor
| authentication would be advisory: an attacker with the password would post to
| /auth/login, take the challenge token out of a 200 response, and use it as a
| session — and the second factor would have prevented nothing at all.
|
*/

/**
 * A confirmed 2FA user plus the plaintext challenge token a login hands out.
 *
 * @return array{0: Company, 1: User, 2: string, 3: string} company, user, secret, challenge token
 */
function challengeHolder(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create([
        'email' => 'grace@acme-analytics.com',
        'password' => 'correct-horse-battery',
        'role' => User::ROLE_OWNER,
    ]);

    $secret = Totp::generateSecret();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $token = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertOk()->json('challenge_token');

    return [$company, $user->refresh(), $secret, $token];
}

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The boundary
|--------------------------------------------------------------------------
*/

it('refuses a challenge token on every authenticated route', function (string $method, string $uri) {
    [$company, $user, $secret, $challenge] = challengeHolder();

    $this->withToken($challenge)
        ->json($method, $uri, [])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    // The one that matters most: /auth/me is what the SPA calls to decide it is
    // logged in. If a challenge token answered here, the second factor would be
    // a dialog the client could simply skip.
    ['get', '/api/v1/auth/me'],
    ['post', '/api/v1/auth/logout'],
    ['get', '/api/v1/auth/tokens'],
    ['delete', '/api/v1/account'],
    ['get', '/api/v1/settings/profile'],
    ['patch', '/api/v1/settings/profile'],
    ['patch', '/api/v1/settings/password'],
    ['get', '/api/v1/settings/api-keys'],
    ['post', '/api/v1/settings/api-keys'],
    ['get', '/api/v1/settings/team'],
    ['get', '/api/v1/feedbacks'],
    ['get', '/api/v1/overview/kpis'],
    ['get', '/api/v1/integrations'],
    ['get', '/api/v1/billing/subscription'],
    ['get', '/api/v1/notifications'],
    // Turning the second factor off with the credential the second factor
    // issued would be the neatest bypass of all.
    ['delete', '/api/v1/auth/two-factor'],
    ['post', '/api/v1/auth/two-factor'],
    ['post', '/api/v1/auth/two-factor/confirm'],
    ['post', '/api/v1/auth/two-factor/recovery-codes'],
]);

it('refuses a challenge token on the private broadcast channel', function () {
    [$company, $user, $secret, $challenge] = challengeHolder();

    // The broadcasting route builds its own middleware array in
    // bootstrap/app.php; it carries no route name, so it reaches the same
    // branch by a different path.
    $this->withToken($challenge)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-company.{$company->id}",
            'socket_id' => '1.1',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('opens exactly one door', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    [$company, $user, $secret, $challenge] = challengeHolder();

    $this->withToken($challenge)->getJson('/api/v1/auth/me')->assertStatus(403);

    $this->withToken($challenge)
        ->postJson('/api/v1/auth/two-factor/challenge', [
            'code' => Totp::codeAt($secret, Carbon::now()->getTimestamp()),
        ])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| The abilities are read positively, in both directions
|--------------------------------------------------------------------------
*/

it('does not promote a legacy wildcard token into a challenge token', function () {
    // The inverse of the API-key trap: `can()` answers true for every ability
    // on a ['*'] token, so a challenge check written with it — or written as
    // "lacks the session ability" — would classify every credential minted
    // before W10 as half-authenticated and lock the existing user base out of
    // the entire API.
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();
    $legacy = $user->createToken('old-web');

    expect($legacy->accessToken->abilities)->toBe(['*'])
        ->and(TokenAbility::isChallenge($legacy->accessToken))->toBeFalse()
        ->and(TokenAbility::isSession($legacy->accessToken))->toBeTrue();

    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('does not let a session or an api key stand in for a challenge token', function (array $abilities) {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    $secret = Totp::generateSecret();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $token = $user->createToken('other', $abilities)->plainTextToken;

    // The challenge route resolves the bearer itself, so this is the check that
    // stops it from becoming a second, guard-free front door into a session.
    $this->withToken($token)
        ->postJson('/api/v1/auth/two-factor/challenge', [
            'code' => Totp::codeAt($secret, Carbon::now()->getTimestamp()),
        ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
})->with([
    'a device session' => [[TokenAbility::SESSION]],
    'an api key' => [[TokenAbility::API]],
    'a legacy wildcard' => [['*']],
]);

it('classifies the three abilities as mutually exclusive', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    $session = $user->createToken('laptop', TokenAbility::session())->accessToken;
    $api = $user->createToken('ci', TokenAbility::api())->accessToken;
    $challenge = $user->createToken('2fa', TokenAbility::challenge())->accessToken;

    expect(TokenAbility::isSession($session))->toBeTrue()
        ->and(TokenAbility::isApiKey($session))->toBeFalse()
        ->and(TokenAbility::isChallenge($session))->toBeFalse()
        ->and(TokenAbility::isApiKey($api))->toBeTrue()
        ->and(TokenAbility::isChallenge($api))->toBeFalse()
        ->and(TokenAbility::isSession($api))->toBeFalse()
        ->and(TokenAbility::isChallenge($challenge))->toBeTrue()
        ->and(TokenAbility::isApiKey($challenge))->toBeFalse()
        ->and(TokenAbility::isSession($challenge))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Lifetime
|--------------------------------------------------------------------------
*/

it('bounds the challenge token to five minutes', function () {
    [$company, $user, $secret, $challenge] = challengeHolder();

    $token = PersonalAccessToken::findToken($challenge);

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and($token->expires_at->lessThanOrEqualTo(now()->addMinutes(TokenLifetime::CHALLENGE_MINUTES)))->toBeTrue()
        // Emphatically shorter than a session: this is a half-authenticated
        // state, not a credential anyone should be able to sit on.
        ->and($token->expires_at->lessThan(now()->addMinutes((int) config('sanctum.session_expiration'))))->toBeTrue();
});
