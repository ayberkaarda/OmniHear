<?php

namespace App\Listeners;

use App\Events\FeedbackIngested;
use App\Jobs\AnalyzeFeedbackJob;

/**
 * The ingestion -> analysis seam (docs/contracts/wave2-seams.md section 2).
 *
 * F4 announces that a genuinely new feedback row exists; this listener is the
 * only thing that turns that into an AnalyzeFeedbackJob. Keeping the dispatch
 * on this side means ingestion never has to know about quota, the analyzer or
 * the retry policy - and that the two workstreams could be built in parallel.
 *
 * Auto-discovered: Laravel scans app/Listeners and registers by the handle()
 * type hint, so no provider edit is needed.
 *
 * Not queued. The listener does nothing but push a job; running it through the
 * queue would mean a job whose only purpose is to dispatch another job, and
 * would put the analysis of a new feedback behind two queue hops.
 */
class QueueFeedbackAnalysis
{
    public function handle(FeedbackIngested $event): void
    {
        AnalyzeFeedbackJob::dispatch($event->companyId, $event->feedbackId);
    }
}
