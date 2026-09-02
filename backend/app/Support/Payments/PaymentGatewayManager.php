<?php

namespace App\Support\Payments;

use App\Support\Payments\Iyzico\IyzicoGateway;
use App\Support\Payments\Stripe\StripeGateway;
use InvalidArgumentException;

/**
 * Resolves a provider key to its gateway.
 *
 * Both implementations are constructor-injected rather than pulled from the
 * container on demand, so the set of supported providers is a compile-time
 * fact and adding a third one cannot be forgotten here.
 */
final class PaymentGatewayManager
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly IyzicoGateway $iyzico,
    ) {}

    public function for(string $provider): PaymentGateway
    {
        return match ($provider) {
            StripeGateway::PROVIDER => $this->stripe,
            IyzicoGateway::PROVIDER => $this->iyzico,
            // Unreachable through the API: the request is validated against
            // Subscription::PROVIDERS first. Guards against an internal caller.
            default => throw new InvalidArgumentException('Unknown payment provider: '.$provider),
        };
    }
}
