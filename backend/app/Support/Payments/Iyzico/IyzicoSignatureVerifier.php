<?php

namespace App\Support\Payments\Iyzico;

use App\Support\Payments\WebhookSignatureException;

/**
 * Verifies `X-IYZ-SIGNATURE-V3` against the raw request body.
 *
 * Iyzico sends that header and nothing else — no timestamp, no nonce, no event
 * id (PROGRESS, verified facts 2026-09-02). Two consequences follow, and both
 * shape this phase:
 *
 *  - There is no timestamp to bound, so there is no equivalent of Stripe's
 *    tolerance window. Replay protection rests entirely on the derived
 *    `webhook_events.event_id` and its unique index (invariant I3).
 *  - The digest encoding could not be confirmed without a live sandbox
 *    account, so it is read from config rather than hard-coded. Switching
 *    `IYZICO_SIGNATURE_ENCODING` to `base64` is the whole change.
 */
final class IyzicoSignatureVerifier
{
    public const PROVIDER = 'iyzico';

    /**
     * @throws WebhookSignatureException
     */
    public function verify(string $rawBody, ?string $header): void
    {
        $secret = config('iyzico.webhook_secret');

        // Fail closed, same as Stripe: no secret means no trusted caller.
        if (! is_string($secret) || $secret === '') {
            throw new WebhookSignatureException(self::PROVIDER, 'signing_secret_not_configured');
        }

        if (! is_string($header) || $header === '') {
            throw new WebhookSignatureException(self::PROVIDER, 'header_missing');
        }

        if (! hash_equals($this->digest($rawBody, $secret), $header)) {
            throw new WebhookSignatureException(self::PROVIDER, 'digest_mismatch');
        }
    }

    /**
     * HMAC-SHA256 over the raw body, encoded as configured.
     *
     * Also used by the test suite to sign a fixture, so a change to the scheme
     * cannot leave the tests asserting against a stale expectation.
     */
    public function digest(string $rawBody, string $secret): string
    {
        $encoding = config('iyzico.signature_encoding', 'hex');

        return $encoding === 'base64'
            ? base64_encode(hash_hmac('sha256', $rawBody, $secret, binary: true))
            : hash_hmac('sha256', $rawBody, $secret);
    }
}
