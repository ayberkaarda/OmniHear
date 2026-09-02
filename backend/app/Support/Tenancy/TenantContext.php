<?php

namespace App\Support\Tenancy;

use Closure;

/**
 * The single source of truth for "which company is this request/job acting as".
 *
 * Bound as a singleton in AppServiceProvider. Everything tenant-scoped reads
 * from here rather than from the authenticated user, because queue workers run
 * without an authenticated user and must still be scoped.
 */
class TenantContext
{
    private ?int $companyId = null;

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * Set the context, run the callback, restore the previous value in a finally.
     *
     * A queue worker is a long-lived process: without the restore, a job that
     * forgets to clear the context leaks its tenant into the next job.
     */
    public function runFor(int $companyId, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
