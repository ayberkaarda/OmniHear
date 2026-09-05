<?php

namespace App\Support\Payments\Stripe;

use App\Support\Payments\WebhookSignatureException;

/**
 * Verifies a `Stripe-Signature` header against the raw request body.
 *
 * Implemented directly rather than through stripe-php. Adding the SDK would
 * mean editing composer.json and rewriting vendor/ while two other workstreams are
 * running `php artisan test` in the same working tree; the scheme itself is
 * twenty lines and fully specified, so the dependency buys nothing here.
 *
 * Header shape: `t=<unix>,v1=<hex>,v1=<hex>` — several v1 values appear while a
 * secret is being rotated. The signed payload is `<t>.<raw body>` keyed with the
 * endpoint signing secret.
 */
final class StripeSignatureVerifier
{
    public const PROVIDER = 'stripe';

    public const HEADER = 'Stripe-Signature';

    /**
     * @throws WebhookSignatureException
     */
    public function verify(string $rawBody, ?string $header): void
    {
        $secret = config('stripe.webhook_secret');

        // Fail closed. An unset secret must never mean "accept everything".
        if (! is_string($secret) || $secret === '') {
            throw new WebhookSignatureException(self::PROVIDER, 'signing_secret_not_configured');
        }

        if (! is_string($header) || $header === '') {
            throw new WebhookSignatureException(self::PROVIDER, 'header_missing');
        }

        [$timestamp, $signatures] = $this->parse($header);

        if ($timestamp === null) {
            throw new WebhookSignatureException(self::PROVIDER, 'timestamp_missing');
        }

        if ($signatures === []) {
            throw new WebhookSignatureException(self::PROVIDER, 'no_v1_signature');
        }

        $tolerance = (int) config('stripe.signature_tolerance', 300);

        // Fail closed. A non-positive tolerance - a mis-set or non-numeric env
        // cast to 0 - must not mean "skip the timestamp check and accept a replay
        // of any age". config/stripe.php already clamps to >= 1; this rejects
        // rather than trusts anything that slips past that.
        if ($tolerance <= 0) {
            throw new WebhookSignatureException(self::PROVIDER, 'tolerance_not_configured');
        }

        if (abs(time() - $timestamp) > $tolerance) {
            throw new WebhookSignatureException(self::PROVIDER, 'timestamp_outside_tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $candidate) {
            // hash_equals, not ===: a byte-by-byte early return leaks the
            // position of the first wrong character over enough samples.
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new WebhookSignatureException(self::PROVIDER, 'digest_mismatch');
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }

    /**
     * Builds the header a given body would be delivered with. Used by the test
     * suite so no fixture has to carry a signature that would go stale the
     * moment the tolerance window moved.
     */
    public function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret));
    }
}
