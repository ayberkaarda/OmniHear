<?php

use App\Http\Middleware\ThrottleFailedAuthentication;
use App\Models\Company;
use App\Models\User;
use App\Support\Auth\TokenAbility;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The limiter an anonymous caller actually meets
|--------------------------------------------------------------------------
|
| Every request here goes through the real HTTP stack. That is the whole point:
| the closure behind `throttle:api` looks limited when read on its own, and
| calling it directly would "prove" a protection that the middleware ordering
| makes unreachable. Only the stack can tell the difference.
|
*/

beforeEach(function () {
    RateLimiter::clear(ThrottleFailedAuthentication::KEY_PREFIX.'127.0.0.1');

    // Three, not sixty, so the test is about the behaviour rather than about
    // how long it takes to send sixty requests.
    config([
        'auth.failed_authentication.max_attempts' => 3,
        'auth.failed_authentication.decay_minutes' => 1,
    ]);
});

it('stops an invalid bearer token after the ceiling instead of answering 401 forever', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    $this->withToken('not-a-real-token')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});

it('limits a request that carries no credential at all', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    $this->getJson('/api/v1/auth/me')->assertStatus(429);
});

it('shares one budget across the whole api surface', function () {
    // An attacker moving between endpoints must not get a fresh allowance for
    // each one - the cost being bounded is the token lookup, not the route.
    $this->withToken('bad')->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withToken('bad')->getJson('/api/v1/feedbacks')->assertStatus(401);
    $this->withToken('bad')->deleteJson('/api/v1/account')->assertStatus(401);

    $this->withToken('bad')->getJson('/api/v1/settings/profile')->assertStatus(429);
});

it('never spends the budget on traffic that authenticates', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();
    $token = $user->createToken('laptop', TokenAbility::session())->plainTextToken;

    // Far more successful requests than the ceiling. If the limiter counted
    // requests rather than failures, the next line would already be a 429 and
    // every tenant behind a shared address would be locked out by its
    // neighbours.
    for ($i = 0; $i < 10; $i++) {
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    // Test-harness detail, not product behaviour: the sanctum guard is a
    // RequestGuard that memoizes its resolved user, and a Pest test sends
    // every request through the same container - so without this the next
    // request would still be "logged in" as $user whatever token it carries.
    $this->app['auth']->forgetGuards();

    // The whole failure budget is still there.
    for ($i = 0; $i < 3; $i++) {
        $this->withToken('bad')->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    $this->withToken('bad')->getJson('/api/v1/auth/me')->assertStatus(429);
});

it('lets a valid session through until the ceiling is reached', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();
    $token = $user->createToken('laptop', TokenAbility::session())->plainTextToken;

    $this->withToken('bad')->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('can be switched off entirely', function () {
    config(['auth.failed_authentication.max_attempts' => 0]);

    for ($i = 0; $i < 6; $i++) {
        $this->withToken('bad')->getJson('/api/v1/auth/me')->assertStatus(401);
    }
});
