<?php

namespace App\Support\Connectors;

use RuntimeException;
use Throwable;

/**
 * The only exception a connector may throw out of fetchPage().
 *
 * getSafeMessage() is what may be persisted or logged. getMessage() carries the
 * same fixed sentence, so even a handler that ignores the distinction and logs
 * the exception cannot leak anything: nothing variable is ever put into it. A
 * previous exception may be attached for the stack trace, but its message is
 * never copied into ours.
 */
final class ConnectorException extends RuntimeException
{
    private function __construct(
        private readonly ConnectorFailure $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure->safeMessage(), 0, $previous);
    }

    public static function of(ConnectorFailure $failure, ?Throwable $previous = null): self
    {
        return new self($failure, $previous);
    }

    public function failure(): ConnectorFailure
    {
        return $this->failure;
    }

    public function getSafeMessage(): string
    {
        return $this->failure->safeMessage();
    }

    public function isTransient(): bool
    {
        return $this->failure->isTransient();
    }
}
