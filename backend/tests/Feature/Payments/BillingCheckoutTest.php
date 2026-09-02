<?php

use App\Models\User;
use App\Support\Payments\CheckoutReference;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| POST /api/v1/billing/checkout
|--------------------------------------------------------------------------
|
| No live provider account exists, so every outbound call is faked and the
| responses come from the recorded fixtures under
| tests/Fixtures/webhooks/{stripe,iyzico}/.
|
*/

beforeEach(function () {
    PaymentTestKit::configure();
});

it('starts a Stripe checkout for the owner', function () {
    Http::fake([
        PaymentTestKit::STRIPE_API_BASE.'/v1/checkout/sessions' => Http::response(
            PaymentTestKit::fixture('stripe', 'checkout-session-created-response'),
        ),
    ]);

    [, $owner] = tenant();
    $fixture = PaymentTestKit::fixture('stripe', 'checkout-session-created-response');

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertOk()
        ->assertExactJson([
            'provider' => 'stripe',
            'checkout_url' => $fixture['url'],
            'session_id' => $fixture['id'],
        ]);
});

it('sends Stripe a reference that names the tenant', function () {
    // This is the field the webhook resolves the tenant from. If it stops being
    // sent, activation silently stops working for every new customer.
    Http::fake([
        PaymentTestKit::STRIPE_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('stripe', 'checkout-session-created-response'),
        ),
    ]);

    [$company, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertOk();

    Http::assertSent(function (Request $request) use ($company): bool {
        $body = [];
        parse_str($request->body(), $body);

        return $request->url() === PaymentTestKit::STRIPE_API_BASE.'/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.PaymentTestKit::STRIPE_API_TOKEN)
            && $body['mode'] === 'subscription'
            && $body['line_items'][0]['price'] === PaymentTestKit::STRIPE_PRICE_PRO
            && CheckoutReference::companyId($body['client_reference_id']) === $company->id
            && $body['metadata']['plan'] === 'pro';
    });
});

it('starts an Iyzico checkout for the owner', function () {
    Http::fake([
        PaymentTestKit::IYZICO_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('iyzico', 'checkoutform-initialize-response'),
        ),
    ]);

    [, $owner] = tenant();
    $fixture = PaymentTestKit::fixture('iyzico', 'checkoutform-initialize-response');

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'iyzico', 'plan' => 'pro'])
        ->assertOk()
        ->assertExactJson([
            'provider' => 'iyzico',
            'checkout_url' => $fixture['data']['payWithIyzicoPageUrl'],
            'session_id' => $fixture['data']['token'],
        ]);
});

it('signs the Iyzico request with the IYZWSv2 scheme', function () {
    Http::fake([
        PaymentTestKit::IYZICO_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('iyzico', 'checkoutform-initialize-response'),
        ),
    ]);

    [$company, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'iyzico', 'plan' => 'pro'])
        ->assertOk();

    Http::assertSent(function (Request $request) use ($company): bool {
        $random = $request->header('x-iyzi-rnd')[0] ?? '';
        $path = '/v2/subscription/checkoutform/initialize';

        $expected = 'IYZWSv2 '.base64_encode(sprintf(
            'apiKey:%s&randomKey:%s&signature:%s',
            PaymentTestKit::IYZICO_API_KEY,
            $random,
            hash_hmac('sha256', $random.$path.$request->body(), PaymentTestKit::IYZICO_SECRET_KEY),
        ));

        $body = json_decode($request->body(), true);

        return $request->hasHeader('Authorization', $expected)
            && $body['pricingPlanReferenceCode'] === PaymentTestKit::IYZICO_PRICING_PLAN_PRO
            && CheckoutReference::companyId($body['conversationId']) === $company->id;
    });
});

it('never reuses a checkout reference', function () {
    Http::fake([
        '*' => Http::response(PaymentTestKit::fixture('stripe', 'checkout-session-created-response')),
    ]);

    [, $owner] = tenant();

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
            ->assertOk();
    }

    $references = [];

    Http::assertSentCount(2);
    Http::recorded(function (Request $request) use (&$references): bool {
        $body = [];
        parse_str($request->body(), $body);
        $references[] = $body['client_reference_id'];

        return true;
    });

    expect($references)->toHaveCount(2)
        ->and(array_unique($references))->toHaveCount(2);
});

it('forbids a member from starting a checkout', function () {
    Http::fake();

    [, $member] = tenant(User::ROLE_MEMBER);

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    Http::assertNothingSent();
});

it('forbids an admin from starting a checkout', function () {
    // A recurring charge is the owner's decision, not an admin's (spec 8).
    Http::fake();

    [, $admin] = tenant(User::ROLE_ADMIN);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    Http::assertNothingSent();
});

it('requires authentication', function () {
    Http::fake();

    $this->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    Http::assertNothingSent();
});

it('validates the provider and the plan', function () {
    Http::fake();

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'paypal', 'plan' => 'pro'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['provider']]);

    // `free` is a plan, but not one that is sold.
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'free'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['plan']]);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['provider', 'plan']]);

    Http::assertNothingSent();
});

it('answers 502 when Stripe rejects the call', function () {
    Http::fake([
        PaymentTestKit::STRIPE_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('stripe', 'checkout-session-error-response'),
            400,
        ),
    ]);

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertExactJson([
            'code' => 'PAYMENT_PROVIDER_ERROR',
            'message' => 'The payment provider returned an error. No charge was made.',
        ]);

    // Nothing local was written, so a retry starts from a clean slate.
    $this->assertDatabaseCount('subscriptions', 0);
});

it('answers 502 when Iyzico answers 200 with a business failure', function () {
    // Iyzico signals business errors inside a 200 body, so the HTTP status
    // alone is not the success signal.
    Http::fake([
        PaymentTestKit::IYZICO_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('iyzico', 'checkoutform-initialize-failure'),
        ),
    ]);

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'iyzico', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');
});

it('answers 502 when the provider is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');
});

it('answers 502 when the provider returns a shape we cannot use', function () {
    Http::fake([PaymentTestKit::STRIPE_API_BASE.'/*' => Http::response(['id' => 'cs_test_no_url'])]);

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');
});

it('answers 502 when the provider is not configured, and leaks nothing about why', function () {
    Http::fake();

    config(['stripe.secret' => null]);

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertExactJson([
            'code' => 'PAYMENT_PROVIDER_ERROR',
            'message' => 'The payment provider returned an error. No charge was made.',
        ]);

    Http::assertNothingSent();
});

it('answers 502 when the plan has no configured price', function () {
    Http::fake();

    config(['stripe.prices.pro' => null]);

    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');

    Http::assertNothingSent();
});
