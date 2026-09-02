<?php

namespace App\Support\Connectors;

use Illuminate\Support\Facades\Cache;

/**
 * One sync at a time per integration.
 *
 * Two concurrent runs of the same integration would fetch the same pages, race
 * on the cursor and — worse — both try to create the same rows. The unique index
 * would keep the data correct (invariant I2), but the second run would waste a
 * full pass over a rate-limited third-party API for nothing.
 *
 * Every dispatch site acquires; the job always releases. That symmetry is what
 * lets POST /integrations/{id}/sync answer 409 SYNC_IN_PROGRESS truthfully: the
 * lock is taken before the job is queued, not once the worker picks it up.
 *
 * The flag is a cache entry with a TTL rather than a column, so a worker that
 * dies mid-run cannot wedge the integration permanently.
 */
class IntegrationSyncLock
{
    public function acquire(int $integrationId): bool
    {
        return Cache::add($this->key($integrationId), true, $this->ttl());
    }

    public function release(int $integrationId): void
    {
        Cache::forget($this->key($integrationId));
    }

    public function isHeld(int $integrationId): bool
    {
        return Cache::has($this->key($integrationId));
    }

    private function key(int $integrationId): string
    {
        return 'integration-sync:'.$integrationId;
    }

    private function ttl(): int
    {
        return max(1, (int) config('connectors.sync_lock_ttl', 600));
    }
}
