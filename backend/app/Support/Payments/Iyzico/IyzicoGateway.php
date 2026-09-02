<?php

namespace App\Support\Payments\Iyzico;

use App\Models\Company;
use App\Models\User;
use App\Support\Payments\CheckoutReference;
use App\Support\Payments\CheckoutSession;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Initialises an iyzico subscription checkout form.
 *
 * Outbound calls are authenticated with the IYZWSv2 scheme: a per-request
 * random string, an HMAC-SHA256 over `random + uriPath + body` keyed with the
 * merchant secret, and the triple packed into a base64 `Authorization` header.
 * That is the documented scheme; it could not be exercised against a live
 * sandbox in this phase, so the header construction is isolated in one private
 * method and asserted by test rather than by a real 200.
 */
final class IyzicoGateway implements PaymentGateway
{
    public const PROVIDER = 'iyzico';

    private const INITIALIZE_PATH = '/v2/subscription/checkoutform/initialize';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function createCheckoutSession(Company $company, User $user, string $plan): CheckoutSession
    {
        $apiKey = $this->requireConfig('iyzico.api_key', 'api_key');
        $secretKey = $this->requireConfig('iyzico.secret_key', 'secret_key');
        $pricingPlan = $this->requireConfig('iyzico.pricing_plans.'.$plan, 'pricing_plans.'.$plan);

        $body = (string) json_encode([
            'locale' => (string) config('iyzico.locale', 'tr'),
            // Echoed back on the notification; this is how the webhook learns
            // which tenant paid, iyzico having no metadata bag of its own.
            'conversationId' => CheckoutReference::for($company->id),
            'pricingPlanReferenceCode' => $pricingPlan,
            'subscriptionInitialStatus' => 'ACTIVE',
            'callbackUrl' => (string) config('iyzico.checkout.callback_url'),
            'customer' => $this->customer($company, $user),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $random = bin2hex(random_bytes(8));

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'Authorization' => $this->authorization($apiKey, $secretKey, $random, $body),
                    'x-iyzi-rnd' => $random,
                    'Accept' => 'application/json',
                ])
                ->timeout((int) config('iyzico.timeout', 15))
                ->post(config('iyzico.api_base').self::INITIALIZE_PATH);
        } catch (ConnectionException $e) {
            throw PaymentProviderException::unreachable(self::PROVIDER, $e);
        }

        if ($response->failed()) {
            throw PaymentProviderException::http(self::PROVIDER, $response->status());
        }

        // Iyzico answers 200 with `status: failure` for business errors, so the
        // HTTP status alone is not the success signal.
        if ($response->json('status') !== 'success') {
            throw PaymentProviderException::malformedResponse(self::PROVIDER);
        }

        $token = $response->json('data.token');
        $url = $response->json('data.payWithIyzicoPageUrl');

        if (! is_string($token) || $token === '' || ! is_string($url) || $url === '') {
            throw PaymentProviderException::malformedResponse(self::PROVIDER);
        }

        return new CheckoutSession(self::PROVIDER, $token, $url);
    }

    /**
     * Iyzico wants a first and last name separately; we store one `name`
     * column. The split is on the last space, with the whole string used for
     * both halves when there is none — a one-word name is common in Turkish
     * account data and must not produce an empty required field.
     *
     * @return array<string, string>
     */
    private function customer(Company $company, User $user): array
    {
        $name = trim($user->name);
        $position = strrpos($name, ' ');

        return [
            'name' => $position === false ? $name : substr($name, 0, $position),
            'surname' => $position === false ? $name : substr($name, $position + 1),
            'email' => $user->email,
            'identityNumber' => (string) $company->id,
            'billingAddress' => [
                'contactName' => $company->name,
                'city' => (string) config('iyzico.checkout.city', 'Istanbul'),
                'country' => (string) config('iyzico.checkout.country', 'Turkey'),
                'address' => $company->name,
            ],
        ];
    }

    /**
     * `IYZWSv2 base64("apiKey:…&randomKey:…&signature:…")`, the signature being
     * a hex HMAC-SHA256 of `randomKey + uriPath + body`.
     */
    private function authorization(string $apiKey, string $secretKey, string $random, string $body): string
    {
        $signature = hash_hmac('sha256', $random.self::INITIALIZE_PATH.$body, $secretKey);

        return 'IYZWSv2 '.base64_encode(sprintf(
            'apiKey:%s&randomKey:%s&signature:%s',
            $apiKey,
            $random,
            $signature,
        ));
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
