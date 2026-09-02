<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A feedback row was created for the first time.
 *
 * The seam between ingestion (F4) and analysis (F5). It is an event rather than
 * a direct dispatch of AnalyzeFeedbackJob so the two phases can be built in
 * parallel: the ingestion side asserts the event fired, the analysis side
 * asserts its listener queues the job, and neither test suite needs the other
 * side's classes to exist.
 *
 * Fired only for genuinely new rows. A re-fetch that hit the
 * UNIQUE(integration_id, external_id) constraint must not fire it — preventing
 * the same comment from being analysed twice is exactly what that index is for
 * (invariant I2), and a second analysis would also burn a second unit of quota.
 *
 * Carries ids, not models: this crosses a queue boundary, where a serialized
 * model is a stale snapshot and a tenant-scoped one may not even resolve.
 */
final class FeedbackIngested
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $feedbackId,
    ) {}
}
