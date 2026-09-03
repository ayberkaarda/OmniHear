<?php

use App\Http\Middleware\EnforceTokenAbility;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use App\Support\Auth\TokenAbility;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| What an API key may do
|--------------------------------------------------------------------------
|
| Every request in this file carries a REAL bearer token, never
| `actingAs($user, 'sanctum')`. That is the whole point of the file: actingAs
| never puts a persisted token in the guard, so a suite written that way
| exercises the routes but never the credential — ability enforcement could be
| deleted outright and not one existing test would go red. `withToken()` sends
| the string the user was handed, through `auth:sanctum`, exactly as a leaked
| key would.
|
*/

/**
 * @return array{0: Company, 1: User, 2: string} company, user, plaintext key
 */
function apiKeyHolder(string $role = User::ROLE_OWNER): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->state(['role' => $role])->create();

    return [$company, $user, $user->createToken('ci-runner', TokenAbility::api())->plainTextToken];
}

/**
 * @return array{0: Company, 1: User, 2: string} company, user, plaintext session
 */
function sessionHolder(string $role = User::ROLE_OWNER): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->state(['role' => $role])->create();

    return [$company, $user, $user->createToken('laptop', TokenAbility::session())->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| The routes the review named, plus the rest of the deny list
|--------------------------------------------------------------------------
*/

it('refuses to destroy the tenant for an api key', function () {
    [$company, $owner, $key] = apiKeyHolder();

    $this->withToken($key)
        ->deleteJson('/api/v1/account')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

it('refuses to mint another api key from an api key', function () {
    [$company, $owner, $key] = apiKeyHolder();

    $this->withToken($key)
        ->postJson('/api/v1/settings/api-keys', ['name' => 'second'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($owner->tokens()->count())->toBe(1);
});

it('refuses to revoke an api key from an api key', function () {
    [$company, $owner, $key] = apiKeyHolder();
    $victim = $owner->createToken('other-runner', TokenAbility::api())->accessToken;

    $this->withToken($key)
        ->deleteJson("/api/v1/settings/api-keys/{$victim->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(PersonalAccessToken::query()->whereKey($victim->id)->exists())->toBeTrue();
});

it('refuses to end a device session from an api key', function () {
    [$company, $owner, $key] = apiKeyHolder();
    $session = $owner->createToken('laptop', TokenAbility::session())->accessToken;

    $this->withToken($key)
        ->deleteJson("/api/v1/auth/tokens/{$session->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(PersonalAccessToken::query()->whereKey($session->id)->exists())->toBeTrue();
});

it('refuses every session-only route to an api key', function (string $method, string $uri) {
    [$company, $owner, $key] = apiKeyHolder();

    $this->withToken($key)
        ->json($method, $uri, [])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    ['get', '/api/v1/auth/tokens'],
    ['post', '/api/v1/auth/email/resend'],
    ['get', '/api/v1/billing/subscription'],
    ['post', '/api/v1/billing/checkout'],
    ['get', '/api/v1/notifications'],
    ['get', '/api/v1/settings/api-keys'],
    ['get', '/api/v1/settings/notifications'],
    ['get', '/api/v1/settings/profile'],
    ['patch', '/api/v1/settings/profile'],
    ['patch', '/api/v1/settings/password'],
    ['get', '/api/v1/settings/team'],
    ['post', '/api/v1/settings/team/invitations'],
    ['post', '/api/v1/integrations'],
]);

it('refuses a private broadcast channel subscription to an api key', function () {
    [$company, $owner, $key] = apiKeyHolder();

    // The broadcasting route builds its own middleware array in
    // bootstrap/app.php and carries no route name, so it exercises the
    // default-deny branch rather than the allow list.
    $this->withToken($key)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-company.{$company->id}",
            'socket_id' => '1.1',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

/*
|--------------------------------------------------------------------------
| What an API key is for
|--------------------------------------------------------------------------
*/

it('lets an api key read the tenant feedback surface', function () {
    [$company, $owner, $key] = apiKeyHolder();
    $feedback = Feedback::factory()->for($company)->create();

    $this->withToken($key)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($key)->getJson('/api/v1/feedbacks')->assertOk();
    $this->withToken($key)->getJson("/api/v1/feedbacks/{$feedback->id}")->assertOk();
    $this->withToken($key)->getJson('/api/v1/overview/kpis')->assertOk();
    $this->withToken($key)->getJson('/api/v1/integrations')->assertOk();
    $this->withToken($key)->getJson('/api/v1/integrations/platforms')->assertOk();
});

it('lets a device session reach everything the api key was refused', function () {
    [$company, $owner, $session] = sessionHolder();

    // Same routes, same tenant, same role: only the credential differs, which
    // is what makes the 403s above about the ability and about nothing else in
    // the stack.
    $this->withToken($session)->getJson('/api/v1/auth/tokens')->assertOk();
    $this->withToken($session)->getJson('/api/v1/settings/api-keys')->assertOk();
    $this->withToken($session)->getJson('/api/v1/settings/profile')->assertOk();
    $this->withToken($session)->getJson('/api/v1/settings/team')->assertOk();
    $this->withToken($session)->getJson('/api/v1/billing/subscription')->assertOk();

    $this->withToken($session)
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertCreated();
});

it('treats a legacy wildcard token as a session rather than promoting it', function () {
    // The trap Sanctum's own `abilities:` middleware falls into: `can()`
    // answers true for every ability on a ['*'] token, so every legacy session
    // would be admitted as an API key. TokenAbility asks the opposite
    // question, and this is the test that says so through the HTTP stack.
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();
    $legacy = $user->createToken('old-web');

    expect($legacy->accessToken->abilities)->toBe(['*']);

    $this->withToken($legacy->plainTextToken)->getJson('/api/v1/settings/api-keys')->assertOk();
    $this->withToken($legacy->plainTextToken)
        ->postJson('/api/v1/settings/api-keys', ['name' => 'from-legacy'])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| The allow list cannot rot
|--------------------------------------------------------------------------
*/

it('classifies every authenticated route and names no route that is gone', function () {
    $authenticated = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $entry) {
            if (is_string($entry) && str_contains($entry, 'auth:sanctum')) {
                $authenticated[] = $route;
                break;
            }
        }
    }

    expect($authenticated)->not->toBeEmpty();

    // Default deny only holds if the middleware is actually on every one of
    // them — a route group that forgets it is the way this regresses.
    foreach ($authenticated as $route) {
        expect($route->gatherMiddleware())->toContain(EnforceTokenAbility::class);
    }

    $names = array_values(array_filter(array_map(
        fn ($route): ?string => $route->getName(),
        $authenticated,
    )));

    // Every machine route still exists...
    expect(array_diff(EnforceTokenAbility::MACHINE_ROUTES, $names))->toBe([]);

    // ...and nothing crept onto the list. Spelling the expected set out here
    // makes widening the machine surface a deliberate two-file change rather
    // than a one-line edit nobody reviews.
    expect(EnforceTokenAbility::MACHINE_ROUTES)->toBe([
        'api.v1.auth.logout',
        'api.v1.auth.me',
        'api.v1.feedbacks.index',
        'api.v1.feedbacks.show',
        'api.v1.integrations.index',
        'api.v1.integrations.platforms',
        'api.v1.integrations.show',
        'api.v1.integrations.sync',
        'api.v1.overview.kpis',
    ]);
});

/*
|--------------------------------------------------------------------------
| Tokens expire
|--------------------------------------------------------------------------
*/

it('bounds every token with a finite ceiling', function () {
    expect((int) config('sanctum.expiration'))->toBeGreaterThan(0);
});

it('stamps an expiry on a session minted by login', function () {
    [$company, $user] = tenant();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'laptop',
    ])->assertOk();

    $token = $user->tokens()->firstOrFail();

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and($token->expires_at->lessThanOrEqualTo(now()->addMinutes((int) config('sanctum.expiration'))))->toBeTrue();
});

it('stamps an expiry on a minted api key', function () {
    [$company, $owner, $session] = sessionHolder();

    $id = $this->withToken($session)
        ->postJson('/api/v1/settings/api-keys', ['name' => 'ci-runner'])
        ->assertCreated()
        ->json('api_key.id');

    expect(PersonalAccessToken::query()->findOrFail($id)->expires_at)->not->toBeNull();
});

it('refuses a token that has outlived its own expiry', function () {
    [$company, $owner, $session] = sessionHolder();

    $owner->tokens()->update(['expires_at' => now()->subMinute()]);

    $this->withToken($session)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses a token older than the sanctum ceiling whatever its own expiry says', function () {
    [$company, $owner, $session] = sessionHolder();

    // Created before the ceiling, expires_at still in the future: the shape of
    // a row a pre-expiry deployment left behind, and the reason the ceiling is
    // set as well as the per-token value.
    $owner->tokens()->update([
        'created_at' => now()->subMinutes((int) config('sanctum.expiration') + 1),
        'expires_at' => now()->addYear(),
    ]);

    $this->withToken($session)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
