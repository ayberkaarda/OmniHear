<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paid subscription became active for a company.
 *
 * The seam between payments (F6/F7) and analysis (F5). Spec 7.5 requires that
 * completing checkout re-queues the feedback that accumulated in
 * `pending_analysis` while the quota was exhausted — but the payment side has no
 * business reaching into quota counters or feedback rows to do it. It announces
 * the fact; F5's listener raises the limit from config/quota.php and re-queues.
 *
 * That split also keeps the two payment providers honest: Stripe and Iyzico
 * arrive through different webhooks with different signatures and different
 * payloads, and both must end in exactly one activation path.
 *
 * Carries ids and scalars, not models, for the same queue-boundary reason as
 * FeedbackIngested.
 */
final class SubscriptionActivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        /** 'stripe' | 'iyzico' */
        public readonly string $provider,
        /** Plan key in config/quota.php, e.g. 'pro'. */
        public readonly string $plan,
    ) {}
}
