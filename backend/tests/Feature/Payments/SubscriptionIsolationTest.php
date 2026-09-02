<?php

use App\Http\Middleware\SetTenantContext;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Payments\SubscriptionActivator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| Invariant I1 on the subscription resource — 404, never 403
|--------------------------------------------------------------------------
|
| The billing contract exposes no /billing/subscription/{id}, so the crossing
| is exercised through ad-hoc probe routes built exactly the way a real one
| would be — the same approach tests/Feature/Tenancy/CrossTenantHttpTest.php
| uses for the F2 models, and the reason the testApiRoute helper exists.
|
| Both locks are tested, because they fail differently: the global scope makes
| the row unfindable, and SubscriptionPolicy denies it as not found for the
| paths where a model arrives from somewhere other than a scoped query.
|
*/

beforeEach(function () {
    PaymentTestKit::configure();

    testApiRoute('get', '_probe/subscriptions/{id}', fn (string $id) => Subscription::findOrFail($id), [
        'auth:sanctum',
        SetTenantContext::class,
    ]);

    testApiRoute('get', '_probe/policy/subscriptions/{id}', function (string $id) {
        // tenant-scope: bypass-ok the probe deliberately hands the policy a row the scope would have hidden, so the policy itself is what is under test
        $subscription = Subscription::withoutGlobalScopes()->findOrFail($id);

        Gate::authorize('view', $subscription);

        return response()->json(['id' => $subscription->id]);
    }, ['auth:sanctum', SetTenantContext::class]);
});

it('answers 404 with the NOT_FOUND code for another tenant subscription', function () {
    [, $ownerA] = tenant();
    $companyB = Company::factory()->create();
    $ofB = asTenant($companyB, fn () => Subscription::factory()->for($companyB)->create());

    $this->actingAs($ownerA, 'sanctum')
        ->getJson("/api/v1/_probe/subscriptions/{$ofB->id}")
        ->assertStatus(404)
        ->assertExactJson([
            'code' => 'NOT_FOUND',
            'message' => 'The requested resource was not found.',
        ]);
});

it('serves the tenant its own subscription', function () {
    [$companyA, $ownerA] = tenant();
    $ofA = asTenant($companyA, fn () => Subscription::factory()->for($companyA)->create());

    $this->actingAs($ownerA, 'sanctum')
        ->getJson("/api/v1/_probe/subscriptions/{$ofA->id}")
        ->assertOk()
        ->assertJsonPath('id', $ofA->id);
});

it('denies another tenant subscription as not found through the policy', function () {
    // 403 would confirm the row exists. The policy answers denyAsNotFound so an
    // enumeration attempt cannot tell "yours, forbidden" from "does not exist".
    [, $ownerA] = tenant();
    $companyB = Company::factory()->create();
    $ofB = asTenant($companyB, fn () => Subscription::factory()->for($companyB)->create());

    $this->actingAs($ownerA, 'sanctum')
        ->getJson("/api/v1/_probe/policy/subscriptions/{$ofB->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $ofA = asTenant($ownerA->company, fn () => Subscription::factory()->for($ownerA->company)->create());

    $this->actingAs($ownerA, 'sanctum')
        ->getJson("/api/v1/_probe/policy/subscriptions/{$ofA->id}")
        ->assertOk();
});

it('grants the checkout decision to the owner alone', function () {
    [$company] = tenant();

    $owner = User::factory()->for($company)->state(['role' => User::ROLE_OWNER])->create();
    $admin = User::factory()->for($company)->state(['role' => User::ROLE_ADMIN])->create();
    $member = User::factory()->for($company)->state(['role' => User::ROLE_MEMBER])->create();

    expect(Gate::forUser($owner)->allows('create', Subscription::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Subscription::class))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', Subscription::class))->toBeFalse();

    // Everyone on the team may read it.
    foreach ([$owner, $admin, $member] as $user) {
        expect(Gate::forUser($user)->allows('viewAny', Subscription::class))->toBeTrue();
    }
});

it('writes a webhook activation under the payload tenant and restores the context', function () {
    // The activator establishes the tenant explicitly because a webhook has no
    // authenticated user, and restores whatever was there before — a queue
    // worker that inherited a leaked tenant would write the next company's data
    // under this one.
    [$companyA] = tenant();
    $companyB = Company::factory()->create();

    $tenantContext = app(TenantContext::class);

    $tenantContext->runFor($companyA->id, function () use ($companyA, $companyB, $tenantContext): void {
        app(SubscriptionActivator::class)->activate(
            $companyB->id,
            'stripe',
            'sub_isolation_probe',
            'pro',
        );

        expect($tenantContext->id())->toBe($companyA->id);
    });

    expect($tenantContext->has())->toBeFalse();

    $this->assertDatabaseHas('subscriptions', [
        'company_id' => $companyB->id,
        'provider_subscription_id' => 'sub_isolation_probe',
    ]);
    $this->assertDatabaseCount('subscriptions', 1);
});
