<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Jobs\RequeuePendingAnalysisJob;
use App\Models\Company;
use App\Support\Overview\KpiCache;
use Illuminate\Support\Facades\Log;

/**
 * The payments -> analysis seam (docs/contracts/wave2-seams.md section 2,
 * spec 7.5).
 *
 * Payments announces that a subscription became active. Raising the quota
 * limit and re-queueing the accumulated backlog happens here, on the analysis
 * side, so that neither provider integration has to reach into quota counters
 * or feedback rows.
 *
 * The new limit comes from config/quota.php and from nowhere else. Every known
 * plan has a configured limit there. If activation ever names a plan with no
 * limit - an unknown plan, or a config regression - the listener leaves the
 * existing limit alone and logs an error rather than writing a guess into a
 * customer's row: that is now a genuine fault, not an expected waypoint. The
 * re-queue still runs regardless: it is idempotent, and any feedback that still
 * does not fit under the old limit is simply parked again.
 */
class ActivateSubscriptionPlan
{
    public function __construct(private readonly KpiCache $kpis) {}

    public function handle(SubscriptionActivated $event): void
    {
        $company = Company::query()->find($event->companyId);

        if ($company === null) {
            return;
        }

        $limit = config('quota.plans.'.$event->plan.'.quota_limit');

        if (is_numeric($limit)) {
            $company->forceFill([
                'plan' => $event->plan,
                'quota_limit' => (int) $limit,
            ])->save();

            // The KPI payload embeds quota.limit (OverviewController::compute),
            // so a cached entry now disagrees with the row that just paid for
            // the new number. Forgetting is done right here, at the write, not
            // by adding a SubscriptionActivated handler to
            // InvalidateKpiCache: DiscoverEvents (Finder::create()->files()->in())
            // registers listeners in unsorted filesystem order, so nothing
            // guarantees that handler would run *after* this one. If it ran
            // before, a dashboard request landing in that window would
            // recompute and re-cache the stale limit, and no test could pin
            // an ordering that isn't guaranteed to begin with. Forgetting
            // immediately after the write it depends on is the only version
            // of this fix that cannot race itself.
            $this->kpis->forget($company->getKey());
        } else {
            $company->forceFill(['plan' => $event->plan])->save();

            // quota_limit did not change here - the KPI payload's quota.limit
            // is still correct, so there is nothing to invalidate. This is an
            // error, not a warning: every known plan carries a limit in
            // config/quota.php, so reaching here means an unknown plan was
            // activated or the config regressed - a real fault to page on.
            Log::error('quota.plan_limit_not_configured', [
                'company_id' => $event->companyId,
                'plan' => $event->plan,
                'provider' => $event->provider,
            ]);
        }

        RequeuePendingAnalysisJob::dispatch($event->companyId);
    }
}
