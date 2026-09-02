<?php

use App\Http\Middleware\SetTenantContext;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Invariant I1 over HTTP — cross-tenant access answers 404, never 403
|--------------------------------------------------------------------------
|
| F2 ships no tenant resource endpoints yet (integrations are F4, feedbacks
| F5), so these exercise the seam through ad-hoc routes built exactly the way
| the real ones will be: auth:sanctum, then SetTenantContext, then findOrFail.
|
*/

beforeEach(function () {
    testApiRoute('get', '_probe/integrations/{id}', fn (string $id) => Integration::findOrFail($id), [
        'auth:sanctum',
        SetTenantContext::class,
    ]);

    testApiRoute('get', '_probe/feedbacks/{id}', fn (string $id) => Feedback::findOrFail($id), [
        'auth:sanctum',
        SetTenantContext::class,
    ]);
});

it('answers 404 with the NOT_FOUND code for another tenant integration', function () {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();
    $ofB = Integration::factory()->for($companyB)->create();

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/integrations/{$ofB->id}")
        ->assertStatus(404)
        ->assertExactJson([
            'code' => 'NOT_FOUND',
            'message' => 'The requested resource was not found.',
        ]);
});

it('answers 404 for another tenant feedback', function () {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();
    $ofB = Feedback::factory()->for($companyB)->create();

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/feedbacks/{$ofB->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('serves the tenant its own row', function () {
    [$companyA, $userA] = tenant();
    $ofA = Integration::factory()->for($companyA)->create();

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/integrations/{$ofA->id}")
        ->assertOk()
        ->assertJsonPath('id', $ofA->id);
});

it('clears the tenant context once the response is built', function () {
    [$companyA, $userA] = tenant();
    $ofA = Integration::factory()->for($companyA)->create();

    $this->actingAs($userA, 'sanctum')->getJson("/api/v1/_probe/integrations/{$ofA->id}")->assertOk();

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('leaves the tenant unset for an unauthenticated request', function () {
    testApiRoute('get', '_probe/open', fn () => response()->json(['ok' => true]), [SetTenantContext::class]);

    $this->getJson('/api/v1/_probe/open')->assertOk();

    expect(app(TenantContext::class)->has())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The two documented exemptions still do not leak
|--------------------------------------------------------------------------
*/

it('denies another tenant user as not found through UserPolicy', function () {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();
    $userB = User::factory()->for($companyB)->create();

    testApiRoute('get', '_probe/users/{user}', function (User $user) {
        Gate::authorize('view', $user);

        return response()->json(['id' => $user->id]);
    }, ['auth:sanctum', SetTenantContext::class]);

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/users/{$userB->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/users/{$userA->id}")
        ->assertOk();
});

it('denies another tenant company as not found through CompanyPolicy', function () {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();

    testApiRoute('get', '_probe/companies/{company}', function (Company $company) {
        Gate::authorize('view', $company);

        return response()->json(['id' => $company->id]);
    }, ['auth:sanctum', SetTenantContext::class]);

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/companies/{$companyB->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $this->actingAs($userA, 'sanctum')
        ->getJson("/api/v1/_probe/companies/{$companyA->id}")
        ->assertOk();
});
