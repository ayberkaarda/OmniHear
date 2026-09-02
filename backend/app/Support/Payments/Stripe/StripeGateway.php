<?php

namespace App\Support\Payments\Stripe;

use App\Models\Company;
use App\Models\User;
use App\Support\Payments\CheckoutReference;
use App\Support\Payments\CheckoutSession;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Creates a Stripe Checkout Session over the REST API.
 *
 * Uses the HTTP client rather than stripe-php for the reason given in
 * StripeSignatureVerifier: the SDK would mean rewriting vendor/ underneath two
 * other agents mid-wave, and everything this class needs is one form POST.
 * `Http::fake()` against tests/Fixtures/webhooks/stripe/ then covers it with no
 * live account, which is the constraint this phase was built under.
 */
final class StripeGateway implements PaymentGateway
{
    public const PROVIDER = 'stripe';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function createCheckoutSession(Company $company, User $user, string $plan): CheckoutSession
    {
        $secret = $this->requireConfig('stripe.secret', 'secret');
        $price = $this->requireConfig('stripe.prices.'.$plan, 'prices.'.$plan);

        $reference = CheckoutReference::for($company->id);

        try {
            $response = Http::asForm()
                ->withToken($secret)
                ->withHeaders(['Stripe-Version' => (string) config('stripe.api_version')])
                ->timeout((int) config('stripe.timeout', 15))
                ->post(config('stripe.api_base').'/v1/checkout/sessions', [
                    'mode' => 'subscription',
                    'success_url' => (string) config('stripe.checkout.success_url'),
                    'cancel_url' => (string) config('stripe.checkout.cancel_url'),
                    // Echoed back on checkout.session.completed; this is how the
                    // webhook learns which tenant paid.
                    'client_reference_id' => $reference,
                    'customer_email' => $user->email,
                    'line_items[0][price]' => $price,
                    'line_items[0][quantity]' => 1,
                    'metadata[company_id]' => (string) $company->id,
                    'metadata[plan]' => $plan,
                    'subscription_data[metadata][company_id]' => (string) $company->id,
                    'subscription_data[metadata][plan]' => $plan,
                ]);
        } catch (ConnectionException $e) {
            throw PaymentProviderException::unreachable(self::PROVIDER, $e);
        }

        if ($response->failed()) {
            throw PaymentProviderException::http(self::PROVIDER, $response->status());
        }

        $id = $response->json('id');
        $url = $response->json('url');

        if (! is_string($id) || $id === '' || ! is_string($url) || $url === '') {
            throw PaymentProviderException::malformedResponse(self::PROVIDER);
        }

        return new CheckoutSession(self::PROVIDER, $id, $url);
    }

    private function requireConfig(string $key, string $label): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw PaymentProviderException::notConfigured(self::PROVIDER, $label);
        }

        return $value;
    }
}
