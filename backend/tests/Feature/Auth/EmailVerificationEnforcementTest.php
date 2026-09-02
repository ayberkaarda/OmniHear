<?php

use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Spec 7.1 — verification is mandatory on the tenant surface
|--------------------------------------------------------------------------
|
| The `verified` alias and the EMAIL_NOT_VERIFIED code both shipped in F2, but
| no route ever used the middleware, so an unverified account reached the whole
| tenant surface. These tests are the thing that keeps that from silently
| coming back: the point of the middleware is the 403, so the 403 is asserted
| against real endpoints, not against a probe route.
|
*/

function unverifiedTenant(string $role = User::ROLE_OWNER): array
{
    [$company, $user] = tenant($role);
    $user->forceFill(['email_verified_at' => null])->save();

    return [$company, $user->refresh()];
}

it('blocks an unverified user from the tenant surface', function (string $method, string $uri) {
    [$company, $user] = unverifiedTenant();

    $this->actingAs($user, 'sanctum')
        ->json($method, $uri)
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with([
    ['get', '/api/v1/integrations'],
    ['post', '/api/v1/integrations'],
    ['get', '/api/v1/feedbacks'],
    ['get', '/api/v1/overview/kpis'],
    ['get', '/api/v1/billing/subscription'],
    ['post', '/api/v1/billing/checkout'],
]);

it('lets the same request through once the address is verified', function () {
    [$company, $user] = unverifiedTenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations')->assertStatus(403);

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations')->assertOk();
});

it('blocks an unverified user from a single tenant record too', function () {
    [$company, $user] = unverifiedTenant();
    $integration = asTenant($company, fn () => Integration::factory()->for($company)->create());

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/integrations/{$integration->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

/*
|--------------------------------------------------------------------------
| The deliberate exceptions (contract section 5)
|--------------------------------------------------------------------------
*/

it('still serves the session routes to an unverified user', function () {
    [$company, $user] = unverifiedTenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertStatus(202);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/tokens')->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('still lets an unverified owner erase the account', function () {
    [$company, $user] = unverifiedTenant();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);
});

/*
|--------------------------------------------------------------------------
| Coverage of the route table itself
|--------------------------------------------------------------------------
|
| The per-endpoint tests above prove the middleware works. This one proves it
| was not forgotten on a route added later: every authenticated /api/v1 route
| must carry `verified` unless it is on the short, argued exception list.
|
*/

it('applies verified to every authenticated api route except the session surface', function () {
    $exempt = [
        // Contract section 5: the SPA renders "check your inbox" from an
        // authenticated session, so these four have to answer without one.
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/email/resend',
        // Session and account lifecycle — see the comment in routes/api.php.
        'api/v1/auth/tokens',
        'api/v1/auth/tokens/{token}',
        'api/v1/account',
        // Broadcast authorization is registered by withBroadcasting() in
        // bootstrap/app.php with its own middleware array. Flagged as an open
        // question in the phase report rather than changed here.
        'api/v1/broadcasting/auth',
    ];

    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => in_array($route->uri(), $exempt, true))
        ->reject(fn ($route) => in_array('verified', $route->gatherMiddleware(), true))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->unique()
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});
