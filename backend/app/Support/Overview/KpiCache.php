<?php

namespace App\Support\Overview;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard KPI cache (spec 2: Redis, KPI aggregation).
 *
 * # The key is the invariant
 *
 * Everything else in this class is a convenience; the key is invariant I1 on a
 * new surface. A cache is exactly where a tenant leak hides from ordinary
 * tests, because the second tenant's request never reaches a query and so
 * never meets CompanyScope: it is answered from a shared store by a string.
 * A key that forgot the company id - or that was built from a request
 * parameter instead of the tenant in context - would serve company A's
 * revenue-shaped numbers to company B, and every test that exercises one
 * tenant at a time would still be green.
 *
 * So the id is the whole key (`kpis:{companyId}`), it is required as an `int`,
 * and it comes from the same TenantContext that scopes the queries whose
 * result is being stored. Tests\Feature\Analysis\OverviewKpiCacheTest asserts
 * the two-tenant case directly.
 *
 * # Not a materialised aggregate
 *
 * The value is the already-computed response payload. The aggregation itself
 * stays in the database, where OverviewController's docblock explains why it
 * belongs; this class only avoids repeating it for every tab of every member
 * of a company between two writes.
 */
class KpiCache
{
    private const PREFIX = 'kpis:';

    /**
     * The cache key for one tenant. Public so tests can assert the shape
     * rather than guess it.
     */
    public static function key(int $companyId): string
    {
        return self::PREFIX.$companyId;
    }

    /**
     * Return the tenant's cached KPIs, computing and storing them on a miss.
     *
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(int $companyId, Closure $compute): array
    {
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return $compute();
        }

        /** @var array<string, mixed> $payload */
        $payload = Cache::remember(self::key($companyId), $ttl, $compute);

        return $payload;
    }

    /**
     * Drop one tenant's entry.
     *
     * Called from App\Listeners\InvalidateKpiCache on every write that moves a
     * number on the dashboard, and from AccountController when the tenant is
     * erased - a company id is not reused, but leaving a deleted tenant's
     * numbers in the store would outlive the data they describe (spec 8).
     */
    public function forget(int $companyId): void
    {
        Cache::forget(self::key($companyId));
    }

    private function ttl(): int
    {
        return (int) config('overview.cache.ttl', 0);
    }
}
