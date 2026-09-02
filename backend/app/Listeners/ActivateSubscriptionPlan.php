<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Jobs\RequeuePendingAnalysisJob;
use App\Models\Company;
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
 * The new limit comes from config/quota.php and from nowhere else. When the
 * plan has no configured limit yet - `plans.pro.quota_limit` is deliberately
 * null until F6/F7 decides the number - the listener leaves the existing limit
 * alone and says so in the log rather than writing a guess into a customer's
 * row. The re-queue still runs: it is idempotent, and any feedback that still
 * does not fit under the old limit is simply parked again.
 */
class ActivateSubscriptionPlan
{
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
        } else {
            $company->forceFill(['plan' => $event->plan])->save();

            Log::warning('quota.plan_limit_not_configured', [
                'company_id' => $event->companyId,
                'plan' => $event->plan,
                'provider' => $event->provider,
            ]);
        }

        RequeuePendingAnalysisJob::dispatch($event->companyId);
    }
}
