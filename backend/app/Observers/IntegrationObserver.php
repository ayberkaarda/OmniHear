<?php

namespace App\Observers;

use App\Models\Integration;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;

/**
 * Audit trail for integration lifecycle changes (spec 5).
 *
 * An observer rather than four lines in IntegrationController: model events
 * fire wherever the row actually changes, so a later phase that adds a second
 * write path does not have to remember to audit it.
 *
 * **Only actor-driven changes are recorded.** The ingestion runner writes
 * `sync_cursor`, `last_synced_at` and `sync_error` on every scheduled run, and
 * flips `status` back to active after a recovery — auditing those would bury
 * the handful of rows a compliance reviewer is looking for under thousands of
 * machine writes, all with a null `user_id`. Background activity is covered by
 * the structured log and by `integration.sync_requested`, which records the
 * human who asked for it.
 */
class IntegrationObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(Integration $integration): void
    {
        $this->record(AuditAction::IntegrationCreated, $integration);
    }

    public function updated(Integration $integration): void
    {
        $this->record(AuditAction::IntegrationUpdated, $integration);
    }

    public function deleted(Integration $integration): void
    {
        $this->record(AuditAction::IntegrationDeleted, $integration);
    }

    private function record(AuditAction $action, Integration $integration): void
    {
        $companyId = (int) $integration->getAttribute('company_id');
        $actor = $this->audit->currentActor($companyId);

        if ($actor === null) {
            return;
        }

        $this->audit->record($action, actor: $actor, subject: $integration, companyId: $companyId);
    }
}
