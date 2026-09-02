<?php

namespace App\Support\Payments;

use App\Events\SubscriptionActivated;
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
     */
    public function activate(
        int $companyId,
        string $provider,
        string $providerSubscriptionId,
        string $plan,
        ?DateTimeInterface $periodStart = null,
        ?DateTimeInterface $periodEnd = null,
    ): Subscription {
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
}
