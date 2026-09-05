<?php

use App\Events\SubscriptionActivated;
use App\Models\Company;
use App\Models\WebhookEvent;
use App\Support\Payments\WebhookStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| POST /api/webhooks/stripe — signature, activation, refusals
|--------------------------------------------------------------------------
|
| Every payload is loaded from tests/Fixtures/webhooks/stripe/ and signed with
| the same verifier the application uses (CONTRIBUTING.md section 2).
|
| Event::fake() names the one event it fakes. A blanket Event::fake() also
| swallows Eloquent's own model events, which is where BelongsToCompany fills
| company_id — the row would then fail its NOT NULL constraint and the test
| would be measuring the fake rather than the code.
|
*/

beforeEach(function () {
    PaymentTestKit::configure();
});

it('rejects a request with no signature header', function () {
    Event::fake([SubscriptionActivated::class]);

    $payload = PaymentTestKit::stripeEvent('checkout-session-completed');
    $rawBody = PaymentTestKit::encode($payload);

    PaymentTestKit::post($this, PaymentTestKit::STRIPE_WEBHOOK_URI, $rawBody, [])
        ->assertStatus(400)
        ->assertExactJson([
            'code' => 'INVALID_WEBHOOK_SIGNATURE',
            'message' => 'The webhook signature could not be verified.',
        ]);

    expect(WebhookEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('rejects a signature computed with the wrong secret', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    $rawBody = PaymentTestKit::encode($payload);
    $forged = PaymentTestKit::stripeHeaders($rawBody, secret: 'a-different-signing-value');

    PaymentTestKit::post($this, PaymentTestKit::STRIPE_WEBHOOK_URI, $rawBody, $forged)
        ->assertStatus(400)
        ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');

    $this->assertDatabaseCount('subscriptions', 0);
    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a valid signature over a different body', function () {
    // The exact bug signature verification exists to catch: the header is a
    // genuine signature, but not of the bytes that arrived.
    [$company] = tenant();
    $signed = PaymentTestKit::encode(PaymentTestKit::stripeEvent('checkout-session-completed', $company->id));
    $delivered = PaymentTestKit::encode(PaymentTestKit::stripeEvent('checkout-session-completed-unknown-tenant'));

    PaymentTestKit::post(
        $this,
        PaymentTestKit::STRIPE_WEBHOOK_URI,
        $delivered,
        PaymentTestKit::stripeHeaders($signed),
    )->assertStatus(400)->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('rejects a signature whose timestamp is outside the tolerance window', function () {
    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    $rawBody = PaymentTestKit::encode($payload);

    $stale = PaymentTestKit::stripeHeaders($rawBody, timestamp: time() - 3600);

    PaymentTestKit::post($this, PaymentTestKit::STRIPE_WEBHOOK_URI, $rawBody, $stale)
        ->assertStatus(400)
        ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('fails closed when the tolerance is non-positive instead of skipping the timestamp check', function () {
    Event::fake([SubscriptionActivated::class]);

    // A non-numeric STRIPE_SIGNATURE_TOLERANCE ("", "none", "5m") casts to 0.
    // The old verifier gated the timestamp check behind `$tolerance > 0`, so a
    // 0 tolerance silently disabled it and a captured request replayed forever.
    // config/stripe.php now clamps to >= 1; this pins the verifier's own guard
    // by forcing the degenerate value straight into config.
    config(['stripe.signature_tolerance' => 0]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    $rawBody = PaymentTestKit::encode($payload);

    // A perfectly fresh, correctly signed request: the only thing wrong is the
    // tolerance. Before the fix this was accepted with 200.
    $wellSigned = PaymentTestKit::stripeHeaders($rawBody);

    PaymentTestKit::post($this, PaymentTestKit::STRIPE_WEBHOOK_URI, $rawBody, $wellSigned)
        ->assertStatus(400)
        ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');

    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('rejects everything when no signing secret is configured', function () {
    // Fail closed: an unset secret must never mean "accept anything".
    config(['stripe.webhook_secret' => null]);

    $payload = PaymentTestKit::stripeEvent('checkout-session-completed');
    $rawBody = PaymentTestKit::encode($payload);

    PaymentTestKit::post($this, PaymentTestKit::STRIPE_WEBHOOK_URI, $rawBody, [
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64),
    ])->assertStatus(400)->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('accepts a well signed request and answers 200', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();

    PaymentTestKit::postStripe($this, PaymentTestKit::stripeEvent('checkout-session-completed', $company->id))
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);
});

it('activates the subscription and dispatches SubscriptionActivated', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);

    PaymentTestKit::postStripe($this, $payload)->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'company_id' => $company->id,
        'provider' => 'stripe',
        'provider_subscription_id' => $payload['data']['object']['subscription'],
        'plan' => 'pro',
        'status' => 'active',
    ]);

    Event::assertDispatched(
        SubscriptionActivated::class,
        fn (SubscriptionActivated $event): bool => $event->companyId === $company->id
            && $event->provider === 'stripe'
            && $event->plan === 'pro',
    );
});

it('records the delivery in webhook_events and stamps processed_at', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);

    PaymentTestKit::postStripe($this, $payload)->assertOk();

    $event = WebhookEvent::query()->sole();

    expect($event->provider)->toBe('stripe')
        ->and($event->event_id)->toBe($payload['id'])
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->payload['data']['object']['id'])->toBe($payload['data']['object']['id']);
});

it('resolves the tenant from metadata when client_reference_id is absent', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    unset($payload['data']['object']['client_reference_id']);

    PaymentTestKit::postStripe($this, $payload)->assertOk();

    $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'status' => 'active']);
});

it('acknowledges an event for an unknown tenant without creating junk rows', function () {
    Event::fake([SubscriptionActivated::class]);

    // The fixture names company 987654; nothing creates it.
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed-unknown-tenant');

    expect(Company::query()->whereKey(987654)->exists())->toBeFalse();

    PaymentTestKit::postStripe($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNKNOWN_TENANT]);

    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('companies', 0);
    Event::assertNotDispatched(SubscriptionActivated::class);

    // The delivery is still recorded — that row is the audit trail for exactly
    // this case, and it is what stops a retry from being reprocessed.
    expect(WebhookEvent::query()->count())->toBe(1);
});

it('acknowledges an event type it does not handle', function () {
    Event::fake([SubscriptionActivated::class]);

    tenant();

    PaymentTestKit::postStripe($this, PaymentTestKit::stripeEvent('invoice-payment-succeeded'))
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNHANDLED_TYPE]);

    $this->assertDatabaseCount('subscriptions', 0);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('refuses to activate a session that has not been paid', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();

    PaymentTestKit::postStripe($this, PaymentTestKit::stripeEvent('checkout-session-completed-unpaid', $company->id))
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNPAID]);

    $this->assertDatabaseCount('subscriptions', 0);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('acknowledges a session whose plan is not one we sell', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    $payload['data']['object']['metadata']['plan'] = 'free';

    PaymentTestKit::postStripe($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::IGNORED_UNKNOWN_PLAN]);

    $this->assertDatabaseCount('subscriptions', 0);
});

it('acknowledges a signed body it cannot parse', function () {
    $rawBody = 'not json at all';

    PaymentTestKit::post(
        $this,
        PaymentTestKit::STRIPE_WEBHOOK_URI,
        $rawBody,
        PaymentTestKit::stripeHeaders($rawBody),
    )->assertOk()->assertExactJson(['status' => WebhookStatus::IGNORED_MALFORMED]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('falls back to the session id when no subscription id is attached', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);
    unset($payload['data']['object']['subscription']);

    PaymentTestKit::postStripe($this, $payload)->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'company_id' => $company->id,
        'provider_subscription_id' => $payload['data']['object']['id'],
    ]);
});

it('leaves the tenant context unset after a webhook', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();

    PaymentTestKit::postStripe($this, PaymentTestKit::stripeEvent('checkout-session-completed', $company->id))
        ->assertOk();

    // The activator sets the context to write the row and restores it in a
    // finally; a queue worker that inherited a leaked tenant would write the
    // next company's data under this one.
    expect(app(TenantContext::class)->has())->toBeFalse();
});
