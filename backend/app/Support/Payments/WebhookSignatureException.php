<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * The request did not come from the provider (spec 7.6).
 *
 * Webhook routes are unauthenticated by necessity — the caller is Stripe or
 * iyzico, not a tenant — so the signature *is* the authentication. This is
 * raised before the body is parsed and answers 400
 * INVALID_WEBHOOK_SIGNATURE: a 4xx tells the provider not to retry, which is
 * correct, because a request that fails verification will fail it again.
 *
 * The `reason` is for our logs only. Telling an unauthenticated caller *why*
 * verification failed — bad digest, stale timestamp, missing header — hands
 * them an oracle for probing the secret.
 */
final class WebhookSignatureException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('%s webhook signature rejected: %s', $provider, $reason));
    }
}
