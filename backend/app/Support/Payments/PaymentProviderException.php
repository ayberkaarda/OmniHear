<?php

namespace App\Support\Payments;

use App\Exceptions\ApiException;
use App\Support\Http\ApiErrorCode;
use Throwable;

/**
 * A payment provider refused, timed out, or answered with something we cannot
 * use. Renders as 502 PAYMENT_PROVIDER_ERROR through ApiErrorResponse.
 *
 * The provider's own message is never forwarded to the client: it is written by
 * a third party and may quote request parameters back at us, which is a
 * credential-leak shape (invariant I5). The catalogue message is the whole
 * response body; the reason lives in the exception chain for the log.
 */
final class PaymentProviderException extends ApiException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(ApiErrorCode::PaymentProviderError, previous: $previous);
    }

    public static function http(string $provider, int $status): self
    {
        return new self($provider, 'http_status:'.$status);
    }

    public static function unreachable(string $provider, Throwable $previous): self
    {
        return new self($provider, 'unreachable', $previous);
    }

    public static function malformedResponse(string $provider): self
    {
        return new self($provider, 'malformed_response');
    }

    /**
     * A required key is absent from config/{stripe,iyzico}.php. Deliberately
     * indistinguishable from any other provider failure on the wire — an
     * unauthenticated caller learns nothing about our configuration.
     */
    public static function notConfigured(string $provider, string $key): self
    {
        return new self($provider, 'not_configured:'.$key);
    }
}
