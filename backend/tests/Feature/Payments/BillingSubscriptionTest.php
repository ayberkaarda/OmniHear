<?php

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| GET /api/v1/billing/subscription
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    PaymentTestKit::configure();
});

it('returns a null subscription and the free plan for a company that has never paid', function () {
    [$company, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertExactJson([
            'subscription' => null,
            'plan' => 'free',
            'quota' => [
                'limit' => $company->quota_limit,
                'used' => 0,
                'remaining' => $company->quota_limit,
            ],
        ]);
});

it('returns the company subscription', function () {
    [$company, $owner] = tenant();

    $subscription = asTenant($company, fn () => Subscription::factory()->for($company)->create([
        'provider' => 'iyzico',
        'plan' => 'pro',
        'status' => 'active',
    ]));

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('subscription.id', $subscription->id)
        ->assertJsonPath('subscription.provider', 'iyzico')
        ->assertJsonPath('subscription.plan', 'pro')
        ->assertJsonPath('subscription.status', 'active');
});

it('never serializes the provider subscription id', function () {
    // It identifies the customer's record inside the provider and has no use in
    // the SPA; the narrower the payload, the less there is to leak.
    [$company, $owner] = tenant();

    asTenant($company, fn () => Subscription::factory()->for($company)->create());

    $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/subscription')->assertOk();

    expect($response->json('subscription'))->not->toHaveKey('provider_subscription_id');
});

it('returns the newest subscription when a company switched provider', function () {
    [$company, $owner] = tenant();

    asTenant($company, function () use ($company): void {
        Subscription::factory()->for($company)->create(['provider' => 'stripe', 'status' => 'canceled']);
        Subscription::factory()->for($company)->create(['provider' => 'iyzico', 'status' => 'active']);
    });

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('subscription.provider', 'iyzico');
});

it('lets a member read the billing state', function () {
    // A member who cannot see the plan cannot understand a 402.
    [, $member] = tenant(User::ROLE_MEMBER);

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('plan', 'free');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/billing/subscription')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('reports the quota the paywall is measured against', function () {
    [$company, $owner] = tenant();

    $company->forceFill(['analyzed_feedback_count' => 150])->save();

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('quota.used', 150)
        ->assertJsonPath('quota.remaining', $company->quota_limit - 150);
});

/*
|--------------------------------------------------------------------------
| Invariant I1 — a subscription never crosses a tenant boundary
|--------------------------------------------------------------------------
*/

it('does not show another tenant subscription', function () {
    [, $ownerA] = tenant();
    $companyB = Company::factory()->create();

    asTenant($companyB, fn () => Subscription::factory()->for($companyB)->create([
        'provider' => 'stripe',
        'status' => 'active',
    ]));

    // CompanyScope keeps B's row out of the result set entirely; it is not
    // filtered out of the response afterwards, it is never selected.
    $this->actingAs($ownerA, 'sanctum')
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('subscription', null)
        ->assertJsonPath('plan', 'free');
});
