<?php

namespace App\Exceptions;

use App\Support\Http\ApiErrorCode;
use RuntimeException;
use Throwable;

/**
 * Any error the API raises on purpose. Carries the catalogue code so the
 * renderer never has to guess.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode->message(), $errorCode->status(), $previous);
    }

    public static function invalidCredentials(): self
    {
        return new self(ApiErrorCode::InvalidCredentials);
    }

    public static function disposableEmail(): self
    {
        return new self(ApiErrorCode::DisposableEmail);
    }

    public static function emailNotVerified(): self
    {
        return new self(ApiErrorCode::EmailNotVerified);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }
}
