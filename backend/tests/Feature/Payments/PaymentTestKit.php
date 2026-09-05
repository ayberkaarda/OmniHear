<?php

namespace Tests\Feature\Payments;

use App\Support\Payments\Iyzico\IyzicoSignatureVerifier;
use App\Support\Payments\Stripe\StripeSignatureVerifier;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared setup for the payment feature tests.
 *
 * Two things it exists to guarantee:
 *
 *  - Every payload comes from a file under tests/Fixtures/webhooks/, never
 *    from inline JSON (CONTRIBUTING.md section 2). A test may substitute the tenant
 *    id — the fixture cannot know which company id the database will hand out —
 *    but the *shape* is always the fixture's.
 *  - Signatures are computed by the same code the application verifies with. A
 *    fixture carrying a stored signature would go stale the moment the scheme
 *    or the tolerance window changed, and the test would keep passing against
 *    a verifier that no longer works.
 *
 * The secrets below are obviously fake strings and are set into config at the
 * start of each test; no real credential exists anywhere in this project.
 */
final class PaymentTestKit
{
    public const STRIPE_WEBHOOK_SECRET = 'omnihear-test-stripe-webhook-signing-value';

    public const STRIPE_API_TOKEN = 'omnihear-test-stripe-api-token';

    public const STRIPE_PRICE_PRO = 'price_EXAMPLE_pro';

    public const STRIPE_API_BASE = 'https://stripe.test';

    public const IYZICO_WEBHOOK_SECRET = 'omnihear-test-iyzico-webhook-signing-value';

    public const IYZICO_API_KEY = 'omnihear-test-iyzico-api-key';

    public const IYZICO_SECRET_KEY = 'omnihear-test-iyzico-merchant-key';

    /** Must match `pricingPlanReferenceCode` in the iyzico fixtures. */
    public const IYZICO_PRICING_PLAN_PRO = 'plan-EXAMPLE-pro';

    public const IYZICO_API_BASE = 'https://iyzico.test';

    public const STRIPE_WEBHOOK_URI = '/api/webhooks/stripe';

    public const IYZICO_WEBHOOK_URI = '/api/webhooks/iyzico';

    /**
     * Pin every payment config key a test can depend on.
     */
    public static function configure(): void
    {
        config([
            'stripe.secret' => self::STRIPE_API_TOKEN,
            'stripe.webhook_secret' => self::STRIPE_WEBHOOK_SECRET,
            'stripe.api_base' => self::STRIPE_API_BASE,
            'stripe.prices.pro' => self::STRIPE_PRICE_PRO,
            'stripe.signature_tolerance' => 300,

            'iyzico.api_key' => self::IYZICO_API_KEY,
            'iyzico.secret_key' => self::IYZICO_SECRET_KEY,
            'iyzico.webhook_secret' => self::IYZICO_WEBHOOK_SECRET,
            'iyzico.api_base' => self::IYZICO_API_BASE,
            'iyzico.pricing_plans.pro' => self::IYZICO_PRICING_PLAN_PRO,
            'iyzico.signature_encoding' => 'hex',
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function fixture(string $provider, string $name): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/webhooks/'.$provider.'/'.$name.'.json';

        if (! is_file($path)) {
            throw new \RuntimeException('Missing webhook fixture: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Webhook fixture is not valid JSON: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A Stripe event fixture, re-pointed at a company that actually exists.
     *
     * @return array<array-key, mixed>
     */
    public static function stripeEvent(string $name, ?int $companyId = null): array
    {
        $payload = self::fixture('stripe', $name);

        if ($companyId !== null) {
            $payload['data']['object']['client_reference_id'] = self::reference($companyId);
            $payload['data']['object']['metadata']['company_id'] = (string) $companyId;
        }

        return $payload;
    }

    /**
     * An iyzico notification fixture, re-pointed at a company that exists.
     *
     * Only rewrites `conversationId` when the fixture already carries one: a
     * renewal notification legitimately has none, and inventing one would hide
     * the fallback resolution path the handler depends on.
     *
     * @return array<array-key, mixed>
     */
    public static function iyzicoEvent(string $name, ?int $companyId = null): array
    {
        $payload = self::fixture('iyzico', $name);

        if ($companyId !== null && isset($payload['conversationId'])) {
            $payload['conversationId'] = self::reference($companyId);
        }

        return $payload;
    }

    /**
     * A CheckoutReference-shaped string for a given company.
     */
    public static function reference(int $companyId): string
    {
        return sprintf('omnihear-%d-0f1e2d3c4b5a6978', $companyId);
    }

    /**
     * @return array<string, string>
     */
    public static function stripeHeaders(string $rawBody, ?int $timestamp = null, ?string $secret = null): array
    {
        return [
            'HTTP_STRIPE_SIGNATURE' => app(StripeSignatureVerifier::class)
                ->sign($rawBody, $secret ?? self::STRIPE_WEBHOOK_SECRET, $timestamp),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function iyzicoHeaders(string $rawBody, ?string $secret = null): array
    {
        return [
            'HTTP_X_IYZ_SIGNATURE_V3' => app(IyzicoSignatureVerifier::class)
                ->digest($rawBody, $secret ?? self::IYZICO_WEBHOOK_SECRET),
        ];
    }

    /**
     * POST a raw body, byte for byte.
     *
     * postJson() re-encodes the array it is given, which would produce a body
     * the signature no longer covers — the exact bug signature verification
     * exists to catch, and therefore the exact bug a test must not introduce.
     *
     * @param  array<string, string>  $headers
     */
    public static function post(TestCase $test, string $uri, string $rawBody, array $headers): TestResponse
    {
        return $test->call('POST', $uri, [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $headers), $rawBody);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function postStripe(TestCase $test, array $payload, ?string $signature = null): TestResponse
    {
        $rawBody = self::encode($payload);

        return self::post($test, self::STRIPE_WEBHOOK_URI, $rawBody, $signature === null
            ? self::stripeHeaders($rawBody)
            : ['HTTP_STRIPE_SIGNATURE' => $signature]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function postIyzico(TestCase $test, array $payload, ?string $signature = null): TestResponse
    {
        $rawBody = self::encode($payload);

        return self::post($test, self::IYZICO_WEBHOOK_URI, $rawBody, $signature === null
            ? self::iyzicoHeaders($rawBody)
            : ['HTTP_X_IYZ_SIGNATURE_V3' => $signature]);
    }
}
