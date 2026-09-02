<?php

namespace App\Support\Payments;

/**
 * What POST /api/v1/billing/checkout returns, normalised across providers.
 *
 * Stripe calls it a Checkout Session and gives back `{id, url}`; iyzico calls
 * it a subscription checkout form and gives back a token plus a hosted page
 * URL. The SPA should not have to care, so the two are flattened here.
 */
final readonly class CheckoutSession
{
    public function __construct(
        /** 'stripe' | 'iyzico' */
        public string $provider,
        public string $id,
        public string $url,
    ) {}

    /**
     * @return array{provider: string, checkout_url: string, session_id: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'checkout_url' => $this->url,
            'session_id' => $this->id,
        ];
    }
}
