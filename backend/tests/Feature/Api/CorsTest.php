<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\QuotaRemainingHeader;
use App\Models\Company;
use App\Models\User;
use App\Support\Auth\TokenAbility;

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| config/cors.php had never been published, so the framework defaults applied:
| `allowed_origins => ['*']` and, the half that actually broke something,
| `exposed_headers => []`.
|
| The SPA runs on :4200 and the API on :8000. A browser hands JavaScript only
| the response headers named in Access-Control-Expose-Headers, so
| `X-Quota-Remaining` reached the browser and was unreadable by
| `quotaInterceptor` on every single response. Jest cannot see this - its
| HttpTestingController has no CORS - and the E2E journey now proves it in a
| real browser. These tests hold the configuration itself.
|
*/

function corsUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    return [$user, $user->createToken('laptop', TokenAbility::session())->plainTextToken];
}

it('names the quota and correlation headers as readable by the browser', function () {
    [$user, $token] = corsUser();
    $origin = rtrim((string) config('app.frontend_url'), '/');

    $response = $this->withToken($token)
        ->withHeader('Origin', $origin)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', $origin);

    $exposed = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers')));

    expect($exposed)->toContain(QuotaRemainingHeader::HEADER)
        ->and($exposed)->toContain(CorrelationId::HEADER)
        // ...and the header is actually on the response it is exposing.
        ->and($response->headers->get(QuotaRemainingHeader::HEADER))->not->toBeNull();
});

it('does not answer as a wildcard origin', function () {
    [$user, $token] = corsUser();

    $response = $this->withToken($token)
        ->withHeader('Origin', 'https://not-our-spa.example')
        ->getJson('/api/v1/auth/me');

    // The request still succeeds - CORS is a browser rule, not server
    // authorization - but the browser is not told it may read the answer.
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://not-our-spa.example');
});

it('answers the preflight the SPA sends before every mutation', function () {
    $origin = rtrim((string) config('app.frontend_url'), '/');

    $response = $this->call('OPTIONS', '/api/v1/settings/profile', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ]);

    $response->assertNoContent(204)
        ->assertHeader('Access-Control-Allow-Origin', $origin);

    expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('PATCH')
        ->and((int) $response->headers->get('Access-Control-Max-Age'))->toBeGreaterThan(0);
});

it('keeps cookie authentication off', function () {
    // supports_credentials would make every allowed origin able to ride a
    // session cookie. The SPA holds a bearer token and needs none.
    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.allowed_origins'))->not->toContain('*');
});
