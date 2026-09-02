<?php

namespace App\Support\Payments;

/**
 * The one identifier that carries the tenant across the provider round trip.
 *
 * A webhook arrives before any tenant is known — `webhook_events` is the single
 * table with no `company_id` for exactly that reason. Something in the payload
 * has to name the company, and the only field both providers echo back
 * unchanged is the merchant-supplied reference: `client_reference_id` on a
 * Stripe Checkout Session, `conversationId` on an iyzico subscription form.
 *
 * The reference is generated at checkout, handed to the provider, and parsed
 * back out in the webhook. It is deliberately *not* a bare company id: a bare
 * integer in an unauthenticated payload invites a forged webhook aimed at
 * another tenant, and while the signature is what actually authenticates the
 * call, a shaped, non-guessable reference means a body that merely looks
 * plausible cannot select a victim. The random half is 64 bits from
 * `random_bytes`.
 */
final class CheckoutReference
{
    private const PREFIX = 'omnihear';

    private const PATTERN = '/^omnihear-(\d+)-[0-9a-f]{16}$/';

    public static function for(int $companyId): string
    {
        return sprintf('%s-%d-%s', self::PREFIX, $companyId, bin2hex(random_bytes(8)));
    }

    /**
     * The company id encoded in a reference, or null when the value is not one
     * of ours. Never throws: it is fed straight from an untrusted payload.
     */
    public static function companyId(mixed $reference): ?int
    {
        if (! is_string($reference) || preg_match(self::PATTERN, $reference, $matches) !== 1) {
            return null;
        }

        $companyId = (int) $matches[1];

        return $companyId > 0 ? $companyId : null;
    }
}
