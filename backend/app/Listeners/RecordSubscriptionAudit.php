<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Models\Company;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;

/**
 * Audit row for a subscription becoming active (spec 5, spec 8).
 *
 * A listener rather than a line in either webhook handler: Stripe and Iyzico
 * arrive through different signatures and different payloads and already
 * converge on this one event, so this is the only place where "a company was
 * upgraded" is stated exactly once for both providers.
 *
 * There is no authenticated user on a webhook request — the caller is the
 * payment provider — so the row carries a null `user_id` and the provider's IP,
 * which is the truthful record of what happened.
 */
class RecordSubscriptionAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SubscriptionActivated $event): void
    {
        // A webhook can name a company that no longer exists — a tenant that
        // erased itself between checkout and the provider's callback. The row
        // has nowhere to go then (audit_logs.company_id is a real foreign key),
        // and a foreign key violation here would fail the webhook and make the
        // provider retry forever. ActivateSubscriptionPlan takes the same exit.
        if (! Company::query()->whereKey($event->companyId)->exists()) {
            return;
        }

        $this->audit->record(
            AuditAction::SubscriptionActivated,
            companyId: $event->companyId,
        );
    }
}
