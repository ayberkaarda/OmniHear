<?php

namespace App\Support\Payments;

use App\Events\SubscriptionActivated;
use App\Models\Scopes\CompanyScope;
use App\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;

/**
 * The single activation path, shared by both providers.
 *
 * Stripe and iyzico arrive through different routes, different signatures and
 * different payload shapes; everything after "this company just bought this
 * plan" is identical, and lives here so it cannot drift between the two.
 *
 * What this deliberately does NOT do: touch `companies.quota_limit`,
 * `companies.plan`, `companies.analyzed_feedback_count`, any `feedbacks` row,
 * or any re-queue job. Spec 7.5 requires the feedback that accumulated in
 * `pending_analysis` to be re-queued after an upgrade — that is F5's listener
 * on the event dispatched below, and raising the plan's quota limit from
 * config/quota.php is theirs too (docs/contracts/wave2-seams.md section 2).
 * Payments announces the fact and stops.
 */
final class SubscriptionActivator
{
    public const STATUS_ACTIVE = 'active';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Upsert the subscription row and announce the activation.
     *
     * Idempotent on `(provider, provider_subscription_id)`, which is the unique
     * key on the table: a provider that legitimately re-sends an activation for
     * a subscription we already know about updates the row rather than
     * duplicating it. Replay of the *same delivery* never gets this far —
     * WebhookPipeline stops it at the event id.
     *
     * Returns **null** when that id already belongs to a different company. The
     * caller answers `ignored_unknown_tenant` and 200; see the guard below for
     * why anything else is worse.
     */
    public function activate(
        int $companyId,
        string $provider,
        string $providerSubscriptionId,
        string $plan,
        ?DateTimeInterface $periodStart = null,
        ?DateTimeInterface $periodEnd = null,
    ): ?Subscription {
        if ($this->claimedByAnotherCompany($companyId, $provider, $providerSubscriptionId)) {
            return null;
        }

        // Subscription carries CompanyScope, which throws when the context is
        // unset. A webhook has no authenticated user, so the tenant resolved
        // from the payload is established explicitly and restored afterwards.
        return $this->tenant->runFor($companyId, function () use (
            $provider,
            $providerSubscriptionId,
            $plan,
            $periodStart,
            $periodEnd,
        ): Subscription {
            $subscription = Subscription::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'provider_subscription_id' => $providerSubscriptionId,
                ],
                [
                    'plan' => $plan,
                    'status' => self::STATUS_ACTIVE,
                    'current_period_start' => $periodStart ?? now(),
                    'current_period_end' => $periodEnd,
                    'canceled_at' => null,
                ],
            );

            SubscriptionActivated::dispatch($subscription->company_id, $provider, $plan);

            return $subscription;
        });
    }

    /**
     * Is this provider subscription id already held by a different tenant?
     *
     * `subscriptions` is unique on `(provider, provider_subscription_id)` with
     * no `company_id` in the key, while the `updateOrCreate` below runs inside
     * `runFor($companyId)` and is therefore company-scoped. The two disagree
     * exactly once: when the id exists under another company. The scoped lookup
     * misses it, the insert hits the unique index, WebhookPipeline deletes the
     * event row and rethrows, and the provider gets a 500.
     *
     * That 500 is the damage. Stripe would retry into the same wall; iyzico
     * gives up after three attempts, so a *legitimate* activation arriving
     * after a collision could be lost outright. A decidable outcome has to
     * answer 2xx, which is the rule the rest of the webhook path already
     * follows.
     *
     * A collision means the payload named a company that does not own the
     * subscription - a spoofed or mis-keyed reference, not a state we should
     * reconcile by moving somebody's paid plan between tenants. Refusing and
     * saying so leaves the `webhook_events` row as the record that it happened.
     *
     * The read is unscoped on purpose: the whole question is about a row in
     * *another* tenant, so a scoped query cannot ask it. Nothing from the row
     * is returned or logged - only the boolean.
     */
    private function claimedByAnotherCompany(int $companyId, string $provider, string $providerSubscriptionId): bool
    {
        // tenant-scope: bypass-ok cross-tenant uniqueness check; only a boolean leaves this method
        $owner = Subscription::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('provider', $provider)
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->value('company_id');

        return $owner !== null && (int) $owner !== $companyId;
    }
}
