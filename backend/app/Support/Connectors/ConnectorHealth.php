<?php

namespace App\Support\Connectors;

/**
 * The answer to "can this integration reach its platform right now".
 *
 * The message is a safe, fixed string from ConnectorFailure — never an upstream
 * body, header or credential (invariant I5), because it is shown to the user
 * and stored in `integrations.sync_error`.
 */
final readonly class ConnectorHealth
{
    private function __construct(
        public bool $healthy,
        public ?ConnectorFailure $failure,
    ) {}

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failing(ConnectorFailure $failure): self
    {
        return new self(false, $failure);
    }

    public function message(): ?string
    {
        return $this->failure?->safeMessage();
    }
}
