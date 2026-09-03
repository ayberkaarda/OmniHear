<?php

use App\Events\SubscriptionActivated;
use App\Models\Company;
use App\Models\Subscription;
use App\Support\Payments\Iyzico\IyzicoGateway;
use App\Support\Payments\Stripe\StripeGateway;
use App\Support\Payments\SubscriptionActivator;
use App\Support\Payments\WebhookStatus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\PaymentTestKit as Kit;

/*
|--------------------------------------------------------------------------
| One provider subscription id, two tenants
|--------------------------------------------------------------------------
|
| `subscriptions` is unique on (provider, provider_subscription_id) with no
| company_id in the key, but SubscriptionActivator's upsert runs inside
| runFor($companyId) and is therefore company-scoped. When the id already
| exists under a different company the scoped lookup misses it, the insert
| violates the unique index, WebhookPipeline removes the event row and
| rethrows, and the provider gets a 500.
|
| That is the worst possible answer: Stripe retries into the same wall and
| iyzico gives up after three attempts, so a legitimate activation arriving
| behind a collision can be lost for good. Every decidable outcome on this path
| answers 2xx, and this one is decidable.
|
*/

beforeEach(function () {
    Kit::configure();
    Event::fake([SubscriptionActivated::class]);
});

/**
 * A subscription already owned by some other tenant, with the given provider id.
 */
function foreignSubscription(string $provider, string $providerSubscriptionId): Subscription
{
    $stranger = Company::factory()->create();

    return asTenant($stranger, fn (): Subscription => Subscription::query()->create([
        'provider' => $provider,
        'provider_subscription_id' => $providerSubscriptionId,
        'plan' => 'pro',
        'status' => SubscriptionActivator::STATUS_ACTIVE,
        'current_period_start' => now()->subDay(),
    ]));
}

it('answers 200 instead of 500 when a stripe id belongs to another tenant', function () {
    $company = Company::factory()->create();
    $payload = Kit::stripeEvent('checkout-session-completed', $company->id);
    $existing = foreignSubscription(StripeGateway::PROVIDER, $payload['data']['object']['subscription']);

    Kit::postStripe($this, $payload)
        ->assertOk()
        ->assertJsonPath('status', WebhookStatus::IGNORED_UNKNOWN_TENANT);

    // The other tenant's row is untouched and no second row was created.
    expect(Subscription::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((int) $existing->fresh()->company_id)->toBe((int) $existing->company_id)
        ->and($existing->fresh()->plan)->toBe('pro');

    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('answers 200 instead of 500 when an iyzico id belongs to another tenant', function () {
    $company = Company::factory()->create();
    $payload = Kit::iyzicoEvent('subscription-order-success', $company->id);
    foreignSubscription(IyzicoGateway::PROVIDER, $payload['subscriptionReferenceCode']);

    Kit::postIyzico($this, $payload)
        ->assertOk()
        ->assertJsonPath('status', WebhookStatus::IGNORED_UNKNOWN_TENANT);

    expect(Subscription::query()->withoutGlobalScopes()->count())->toBe(1);

    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('returns null from the activator rather than throwing', function () {
    $company = Company::factory()->create();
    foreignSubscription(StripeGateway::PROVIDER, 'sub_shared_id');

    $result = app(SubscriptionActivator::class)->activate(
        $company->id,
        StripeGateway::PROVIDER,
        'sub_shared_id',
        'pro',
    );

    expect($result)->toBeNull();
});

it('still activates and still upserts for the company that owns the id', function () {
    $company = Company::factory()->create();

    $first = app(SubscriptionActivator::class)->activate(
        $company->id,
        StripeGateway::PROVIDER,
        'sub_mine',
        'pro',
    );

    $second = app(SubscriptionActivator::class)->activate(
        $company->id,
        StripeGateway::PROVIDER,
        'sub_mine',
        'business',
    );

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->plan)->toBe('business')
        ->and(Subscription::query()->withoutGlobalScopes()->count())->toBe(1);
});
