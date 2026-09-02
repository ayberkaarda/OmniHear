<?php

use App\Events\SubscriptionActivated;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Support\Payments\Iyzico\IyzicoEventId;
use App\Support\Payments\WebhookStatus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| Invariant I3 — a duplicate delivery answers 2xx and acts exactly once
|--------------------------------------------------------------------------
|
| These are genuine replays: the identical payload is delivered twice and the
| assertion is on the side effect, not on an exception being thrown. An
| exception-based test would still pass if the duplicate ran the business logic
| and only then failed the insert, which is the failure this invariant exists
| to prevent.
|
*/

beforeEach(function () {
    PaymentTestKit::configure();
});

it('runs the Stripe activation exactly once across two identical deliveries', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::stripeEvent('checkout-session-completed', $company->id);

    PaymentTestKit::postStripe($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    PaymentTestKit::postStripe($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::DUPLICATE_IGNORED]);

    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('webhook_events', 1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('runs the Iyzico activation exactly once across two identical deliveries', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    PaymentTestKit::postIyzico($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    PaymentTestKit::postIyzico($this, $payload)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::DUPLICATE_IGNORED]);

    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('webhook_events', 1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('survives all three Iyzico delivery attempts', function () {
    // Iyzico retries up to three times (PROGRESS, verified facts 2026-09-02).
    // The whole budget is spent here to prove the second and third attempts are
    // as inert as the first duplicate.
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    foreach (range(1, 3) as $attempt) {
        PaymentTestKit::postIyzico($this, $payload)->assertOk();
    }

    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('webhook_events', 1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('treats a re-serialised Iyzico body with reordered keys as the same delivery', function () {
    // A retry is not guaranteed to be byte-identical. The derived id is built
    // from a canonical form, so key order cannot turn one event into two.
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    PaymentTestKit::postIyzico($this, $payload)->assertOk();

    $reordered = array_reverse($payload, preserve_keys: true);

    expect(PaymentTestKit::encode($reordered))->not->toBe(PaymentTestKit::encode($payload));

    PaymentTestKit::postIyzico($this, $reordered)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::DUPLICATE_IGNORED]);

    $this->assertDatabaseCount('webhook_events', 1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('does not collide two genuinely different Iyzico events', function () {
    Event::fake([SubscriptionActivated::class]);

    [$company] = tenant();
    $first = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);
    $renewal = PaymentTestKit::iyzicoEvent('subscription-order-success-renewal');

    expect(IyzicoEventId::derive($first))->not->toBe(IyzicoEventId::derive($renewal));

    PaymentTestKit::postIyzico($this, $first)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    PaymentTestKit::postIyzico($this, $renewal)
        ->assertOk()
        ->assertExactJson(['status' => WebhookStatus::PROCESSED]);

    // Two distinct deliveries, two event rows, and the renewal updated the one
    // subscription rather than creating a second.
    $this->assertDatabaseCount('webhook_events', 2);
    $this->assertDatabaseCount('subscriptions', 1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 2);
});

it('does not collide two Iyzico events that differ in a single field', function () {
    // The hash covers the whole canonical payload, so the smallest possible
    // difference is enough to separate two events. A scheme keyed on a
    // hand-picked tuple of fields would merge these.
    [$company] = tenant();
    $base = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    $variants = [
        'event time' => ['iyziEventTime' => 1756800001000],
        'order reference' => ['orderReferenceCode' => 'order-EXAMPLE-0099'],
        'status' => ['subscriptionStatus' => 'CANCELED'],
        'price' => ['price' => 599.0],
    ];

    $ids = [IyzicoEventId::derive($base)];

    foreach ($variants as $changed) {
        $ids[] = IyzicoEventId::derive(array_replace($base, $changed));
    }

    expect($ids)->toHaveCount(5)
        ->and(array_unique($ids))->toHaveCount(5);
});

it('keeps the derived id readable and inside the column width', function () {
    [$company] = tenant();
    $eventId = IyzicoEventId::derive(PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id));

    expect($eventId)->toStartWith('iyzico:SUBSCRIPTION_ORDER_SUCCESS:')
        ->and(strlen($eventId))->toBeLessThanOrEqual(255)
        ->and($eventId)->toMatch('/^iyzico:[A-Za-z0-9_.-]+:[0-9a-f]{64}$/');
});

it('labels an event whose type is missing rather than failing', function () {
    $eventId = IyzicoEventId::derive(['subscriptionReferenceCode' => 'sub-EXAMPLE-0001']);

    expect($eventId)->toStartWith('iyzico:unknown:');
});

it('keeps list order significant when canonicalising', function () {
    // Sorting nested lists as if they were objects would make two different
    // payloads look identical. Only object keys are sorted.
    $a = ['items' => ['first', 'second']];
    $b = ['items' => ['second', 'first']];

    expect(IyzicoEventId::derive($a))->not->toBe(IyzicoEventId::derive($b));
});

it('rolls the event row back when the activation fails, so a retry can land', function () {
    // Leaving the row behind would be worse than a duplicate: the retry would
    // be swallowed as a replay and the subscription would never activate. With
    // only three iyzico attempts there is no margin for that.
    [$company] = tenant();
    $payload = PaymentTestKit::iyzicoEvent('subscription-order-success', $company->id);

    Event::listen(SubscriptionActivated::class, function (): void {
        throw new RuntimeException('listener blew up');
    });

    $this->withoutExceptionHandling();

    try {
        PaymentTestKit::postIyzico($this, $payload);
        $this->fail('The failing listener should have propagated.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('listener blew up');
    }

    expect(WebhookEvent::query()->count())->toBe(0);
    expect(Subscription::withoutGlobalScopes()->count())->toBe(0);
});
