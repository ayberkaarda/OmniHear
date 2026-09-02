<?php

use App\Events\SubscriptionActivated;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Support\Payments\WebhookStatus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| POST /api/webhooks/iyzico — signature, derived event id, activation
|--------------------------------------------------------------------------
|
| Iyzico sends X-IYZ-SIGNATURE-V3 and nothing else, carries no native event id,
| and stops retrying after three attempts (PROGRESS, verified facts
| 2026-09-02). Those three facts drive every assertion below.
|
*/

beforeEach(function () {
    PaymentTestKit::configure();
});

it('rejects a request with no signature header', function () {
    Event::fake([SubscriptionActivated::class]);

    $rawBody = PaymentTestKit::encode(PaymentTestKit::iyzicoEvent('subscription-order-success'));

    PaymentTestKit::post($this, PaymentTestKit::IYZICO_WEBHOOK_URI, $rawBody, [])
        ->assertStatus(400)
        ->assertExactJson([
            'code' => 'INVALID_WEBHOOK_SIGNATURE',
            'message' => 'The webhook signature could not be verified.',
        ]);

    expect(WebhookEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('rejects a digest computed with the wrong secret', function () {
    [$company] = tenant();
    $rawBody = PaymentTestKit::encode(PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id));

    PaymentTestKit::post(
        $this,
        PaymentTestKit::IYZICO_WEBHOOK_URI,
        $rawBody,
        PaymentTestKit::iyzicoHeaders($rawBody, secret: 'a-different-signing-value'),
    )->assertStatus(400)->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('rejects a valid digest over a different body', function () {
    [$company] = tenant();
    $signed = PaymentTestKit::encode(PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id));
    $delivered = PaymentTestKit::encode(PaymentTestKit::iyzicoEvent('subscription-order-unknown-tenant'));

    PaymentTestKit::post(
        $this,
        PaymentTestKit::IYZICO_WEBHOOK_URI,
        $delivered,
        PaymentTestKit::iyzicoHeaders($signed),
    )->assertStatus(400)->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('rejects everything when no signing secret is configured', function () {
    config(['iyzico.webhook_secret' => null]);

    $rawBody = PaymentTestKit::encode(PaymentTestKit::iyzicoEvent('subscription-order-success'));

    PaymentTestKit::post($this, PaymentTestKit::IYZICO_WEBHOOK_URI, $rawBody, [
        'HTTP_X_IYZ_SIGNATURE_V3' => str_repeat('0', 64),
    ])->assertStatus(400)->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('verifies a base64 encoded digest when the encoding is switched', function () {
    // The transport encoding of the V3 digest could not be confirmed without a
    // live sandbox account, so it is a config knob. Both branches are covered
    // so flipping it later is a config change and not a code change.
    config(['iyzico.signature_encoding' => 'base64']);

    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);
    $rawBody = PaymentTestKit::encode($payload);
    $digest = base64_encode(hash_hmac('sha256', $rawBody, PaymentTestKit::IYZICO_WEBHOOK_SECRET, binary: true));

    PaymentTestKit::post($this, PaymentTestKit::IYZICO_WEBHOOK_URI, $rawBody, [
        'HTTP_X_IYZ_SIGNATURE_V3' => $digest,
    ])->assertOk()->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    // ... and the hex digest is no longer accepted under that setting.
    $payload['iyziEventTime'] = 1756800999000;
    $rawBody = PaymentTestKit::encode($payload);

    PaymentTestKit::post($this, PaymentTestKit::IYZICO_WEBHOOK_URI, $rawBody, [
        'HTTP_X_IYZ_SIGNATURE_V3' => hash_hmac('sha256', $rawBody, PaymentTestKit::IYZICO_WEBHOOK_SECRET),
    ])->assertStatus(400);
});

it('activates the subscription and dispatches SubscriptionActivated', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    PaymentTestKit::postIyzico($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    $this->assertDatabaseHas('subscriptions', [
        'company_id' => $company->id,
        'provider' => 'iyzico',
        'provider_subscription_id' => $payload['subscriptionReferenceCode'],
        'plan' => 'pro',
        'status' => 'active',
    ]);

    Event::assertDispatched(
        SubscriptionActivated::class,
        fn (SubscriptionActivated $event): bool => $event->companyId === $company->id
            && $event->provider === 'iyzico'
            && $event->plan === 'pro',
    );
});

it('stores the billing period carried by the notification', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    PaymentTestKit::postIyzico($this, $payload)->assertOk();

    $subscription = asTenant($company, fn () => Subscription::query()->sole());

    // Iyzico sends epoch milliseconds on these fields.
    expect($subscription->current_period_start->getTimestamp())
        ->toBe((int) ($payload['startPeriod'] / 1000))
        ->and($subscription->current_period_end->getTimestamp())
        ->toBe((int) ($payload['endPeriod'] / 1000));
});

it('resolves the tenant of a renewal from the subscription recorded earlier', function () {
    // A renewal notification carries no conversationId, so the merchant
    // reference we planted at checkout is gone. The subscription row written by
    // the first activation is the only remaining link to the tenant.
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $renewal = PaymentTestKit::iyzicoEvent('subscription-order-success-renewal');

    expect($renewal)->not->toHaveKey('conversationId');

    asTenant($company, fn () => Subscription::factory()->for($company)->create([
        'provider' => 'iyzico',
        'provider_subscription_id' => $renewal['subscriptionReferenceCode'],
        'status' => 'active',
    ]));

    PaymentTestKit::postIyzico($this, $renewal)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    $this->assertDatabaseCount('subscriptions', 1);

    Event::assertDispatched(
        SubscriptionActivated::class,
        fn (SubscriptionActivated $event): bool => $event->companyId === $company->id,
    );
});

it('acknowledges a notification for an unknown tenant without creating junk rows', function () {
    Event::fake([SubscriptionActivated::class]);

    PaymentTestKit::postIyzico($this, PaymentTestKit::iyzicoEvent('subscription-order-unknown-tenant'))
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNKNOWN_TENANT]);

    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('companies', 0);
    Event::assertNotDispatched(SubscriptionActivated::class);

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('acknowledges a cancellation without activating anything', function () {
    // Cancellation handling is not in this phase's scope; the point of the
    // assertion is that an unhandled lifecycle event cannot activate a plan.
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();

    PaymentTestKit::postIyzico($this, PaymentTestKit::iyzicoEvent('subscription-order-canceled', $company->id))
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNHANDLED_TYPE]);

    $this->assertDatabaseCount('subscriptions', 0);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('acknowledges a notification whose pricing plan is unknown', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);
    $payload['pricingPlanReferenceCode'] = 'plan-that-we-do-not-sell';

    PaymentTestKit::postIyzico($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNKNOWN_PLAN]);

    $this->assertDatabaseCount('subscriptions', 0);
});

it('acknowledges a notification with no subscription reference', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);
    unset($payload['subscriptionReferenceCode']);

    PaymentTestKit::postIyzico($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_MALFORMED]);
});

it('acknowledges a signed body it cannot parse', function () {
    $rawBody = 'not json at all';

    PaymentTestKit::post(
        $this,
        PaymentTestKit::IYZICO_WEBHOOK_URI,
        $rawBody,
        PaymentTestKit::iyzicoHeaders($rawBody),
    )->assertOk()->assertExactJson(['status' => WebhookStatus::IGNORED_MALFORMED]);

    expect(WebhookEvent::query()->count())->toBe(0);
});
