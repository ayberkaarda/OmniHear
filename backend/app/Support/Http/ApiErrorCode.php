<?php

namespace App\Support\Http;

/**
 * The stable machine strings the SPA maps to localized messages.
 *
 * Catalogue: docs/contracts/http-api-v1.md section 2. Adding a case here means
 * adding an entry to lang/en/errors.php and lang/tr/errors.php as well as to
 * the frontend message catalogue; it is a contract change.
 */
enum ApiErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Unauthenticated = 'UNAUTHENTICATED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case DisposableEmail = 'DISPOSABLE_EMAIL';
    case ServerError = 'SERVER_ERROR';

    // Wave 2. Added by the main thread before F4/F5/F6-F7 were dispatched: this
    // enum and the two lang files are the one part of the error contract all
    // three tracks touch, so they are settled here rather than merged afterwards.
    case IntegrationUnavailable = 'INTEGRATION_UNAVAILABLE';
    case IntegrationInvalidCredentials = 'INTEGRATION_INVALID_CREDENTIALS';
    case SyncInProgress = 'SYNC_IN_PROGRESS';
    case AiServiceUnavailable = 'AI_SERVICE_UNAVAILABLE';
    case InvalidWebhookSignature = 'INVALID_WEBHOOK_SIGNATURE';
    case PaymentProviderError = 'PAYMENT_PROVIDER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationError, self::DisposableEmail,
            self::IntegrationInvalidCredentials => 422,
            self::InvalidCredentials, self::Unauthenticated => 401,
            self::EmailNotVerified, self::Forbidden => 403,
            self::NotFound => 404,
            self::QuotaExceeded => 402,
            self::TooManyRequests => 429,
            self::InvalidWebhookSignature => 400,
            self::SyncInProgress => 409,
            self::PaymentProviderError => 502,
            self::IntegrationUnavailable, self::AiServiceUnavailable => 503,
            self::ServerError => 500,
        };
    }

    /**
     * Localized, developer-facing message. The SPA renders its own translation
     * of the code and only falls back to this string.
     */
    public function message(): string
    {
        return (string) __('errors.'.$this->value);
    }

    /**
     * Best-effort mapping for HTTP exceptions raised outside our own code.
     */
    public static function fromStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            402 => self::QuotaExceeded,
            403 => self::Forbidden,
            404 => self::NotFound,
            422 => self::ValidationError,
            429 => self::TooManyRequests,
            default => self::ServerError,
        };
    }
}
