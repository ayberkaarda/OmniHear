<?php

namespace App\Jobs;

use App\Models\Feedback;
use Illuminate\Support\Facades\Log;

/**
 * Sweep the backlog that accumulated while the quota was exhausted and put it
 * back on the analysis queue (spec 7.5).
 *
 * Dispatched by App\Listeners\ActivateSubscriptionPlan when a payment webhook
 * announces SubscriptionActivated. It is a job rather than inline listener work
 * because the backlog is unbounded: a company that sat on a full free quota for
 * a month can have thousands of rows waiting, and a webhook handler must answer
 * the provider in milliseconds.
 *
 * `chunkById` rather than `get()`: the sweep must not load the whole backlog
 * into memory, and it must not be disturbed by rows whose status changes while
 * it runs - which is exactly what happens, because the jobs it dispatches start
 * flipping `analysis_status` immediately. Keyset iteration is stable under that;
 * offset pagination would skip rows.
 */
final class RequeuePendingAnalysisJob extends TenantAwareJob
{
    /**
     * Rows per keyset page. Chosen so the sweep is a handful of queries for a
     * typical backlog without holding a large result set.
     */
    private const CHUNK = 500;

    protected function handleForTenant(): void
    {
        $requeued = 0;

        Feedback::query()
            ->where('analysis_status', Feedback::STATUS_PENDING)
            ->select(['id'])
            ->chunkById(self::CHUNK, function ($feedbacks) use (&$requeued): void {
                foreach ($feedbacks as $feedback) {
                    AnalyzeFeedbackJob::dispatch($this->companyId, $feedback->id);
                    $requeued++;
                }
            });

        Log::info('quota.pending_analysis_requeued', [
            'company_id' => $this->companyId,
            'count' => $requeued,
        ]);
    }
}
