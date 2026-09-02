<?php

namespace App\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Base class for every job that touches tenant data.
 *
 * The tenant travels with the job payload rather than being inferred at run
 * time, and runFor() restores the previous value in a finally, so a long-lived
 * worker cannot leak one job's tenant into the next.
 *
 * The granular queue traits are used instead of the Queueable bundle on
 * purpose: that bundle pulls in SerializesModels, whose __unserialize() cannot
 * re-initialize the readonly $companyId promoted here (PHP refuses to write a
 * readonly property from a subclass scope). Jobs therefore carry ids, not
 * models — which is what a tenant-scoped queue wants anyway, since a
 * serialized model would be re-resolved without a tenant in context.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $companyId) {}

    abstract protected function handleForTenant(): void;

    final public function handle(TenantContext $tenant): void
    {
        $tenant->runFor($this->companyId, fn () => $this->handleForTenant());
    }
}
