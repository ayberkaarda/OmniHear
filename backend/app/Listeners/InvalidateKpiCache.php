<?php

namespace App\Listeners;

use App\Events\FeedbackAnalyzed;
use App\Events\FeedbackIngested;
use App\Support\Overview\KpiCache;

/**
 * Drops a tenant's cached dashboard whenever one of its numbers moves.
 *
 * # Why the invalidation lives here and not at the write site
 *
 * The two writes that change a KPI are IngestionRunner (a new feedback row)
 * and AnalyzeFeedbackJob (an analysis, a status flip and a quota unit). Both
 * already announce what they did as an event, and both belong to designs that
 * have nothing to do with the dashboard. Putting a Cache::forget() inside them
 * would make the ingestion and analysis pipelines depend on a presentation
 * concern, and the next cached surface would add a second line to each. The
 * events are the seam; this listener is the only thing that has to know a KPI
 * cache exists.
 *
 * Auto-discovered: Laravel scans app/Listeners and registers by each handle*
 * method's type hint, so no provider edit is needed - the same mechanism
 * QueueFeedbackAnalysis relies on. Two methods rather than one union-typed
 * parameter, because the pair reads as the explicit list of things that
 * invalidate the dashboard.
 *
 * Not queued. It is one Redis DEL; a queue hop would cost more than the work
 * and would leave a window in which the dashboard is confidently stale.
 */
class InvalidateKpiCache
{
    public function __construct(private readonly KpiCache $cache) {}

    public function handleFeedbackIngested(FeedbackIngested $event): void
    {
        $this->cache->forget($event->companyId);
    }

    public function handleFeedbackAnalyzed(FeedbackAnalyzed $event): void
    {
        $this->cache->forget($event->companyId);
    }
}
