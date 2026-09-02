<?php

namespace App\Support\Connectors;

use App\Support\Http\ApiErrorCode;

/**
 * Why a connector run stopped.
 *
 * The reason is a closed set, and each case maps to one fixed English sentence.
 * That is invariant I5 made structural: there is no code path that can put an
 * upstream response body, a request header or a credential into the string that
 * reaches `integrations.sync_error` or the log, because the string is chosen
 * from this enum rather than built from the failure.
 */
enum ConnectorFailure: string
{
    case Unreachable = 'unreachable';
    case InvalidCredentials = 'invalid_credentials';
    case RateLimited = 'rate_limited';
    case DepthLimitExceeded = 'depth_limit_exceeded';
    case MalformedResponse = 'malformed_response';
    case Misconfigured = 'misconfigured';

    public function safeMessage(): string
    {
        return match ($this) {
            self::Unreachable => 'The platform could not be reached.',
            self::InvalidCredentials => 'The platform rejected the integration credentials.',
            self::RateLimited => 'The platform rate limit was exceeded.',
            self::DepthLimitExceeded => 'The platform refused the requested page depth.',
            self::MalformedResponse => 'The platform returned a response this connector could not parse.',
            self::Misconfigured => 'The integration settings are incomplete for this platform.',
        };
    }

    /**
     * Transient failures are worth another attempt on the queue; terminal ones
     * repeat identically however many times they are retried, so retrying them
     * only delays the error state the user needs to see.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::Unreachable, self::RateLimited => true,
            default => false,
        };
    }

    public function apiErrorCode(): ApiErrorCode
    {
        return match ($this) {
            self::InvalidCredentials => ApiErrorCode::IntegrationInvalidCredentials,
            default => ApiErrorCode::IntegrationUnavailable,
        };
    }
}
