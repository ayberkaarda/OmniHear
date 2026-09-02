<?php

namespace App\Support\Payments\Iyzico;

use App\Models\Company;
use App\Models\Subscription;
use App\Support\Payments\CheckoutReference;
use App\Support\Payments\PaidPlans;
use App\Support\Payments\SubscriptionActivator;
use App\Support\Payments\WebhookStatus;
use Illuminate\Support\Carbon;

/**
 * Turns a verified iyzico subscription notification into an activation.
 *
 * Same refusal policy as the Stripe handler — every decidable condition
 * answers 200 — and it matters more here: iyzico stops retrying after three
 * attempts (PROGRESS, verified facts 2026-09-02), so a 5xx spent on something
 * a retry cannot fix burns a third of the delivery budget.
 */
final class IyzicoWebhookHandler
{
    /**
     * Iyzico names subscription lifecycle events by status rather than by a
     * dotted type. These are the ones that mean "this subscription is now
     * paid and running".
     */
    public const ACTIVATING_STATUSES = ['ACTIVE', 'SUCCESS'];

    public const EVENT_SUBSCRIPTION_ORDER = 'SUBSCRIPTION_ORDER_SUCCESS';

    public function __construct(private readonly SubscriptionActivator $activator) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return string one of WebhookStatus
     */
    public function handle(array $payload): string
    {
        $subscriptionId = $this->stringField($payload, 'subscriptionReferenceCode');

        if ($subscriptionId === null) {
            return WebhookStatus::IGNORED_MALFORMED;
        }

        if (! $this->isActivating($payload)) {
            return WebhookStatus::IGNORED_UNHANDLED_TYPE;
        }

        $companyId = $this->companyId($payload, $subscriptionId);

        if ($companyId === null) {
            return WebhookStatus::IGNORED_UNKNOWN_TENANT;
        }

        $plan = $this->plan($payload);

        if ($plan === null) {
            return WebhookStatus::IGNORED_UNKNOWN_PLAN;
        }

        $this->activator->activate(
            $companyId,
            IyzicoGateway::PROVIDER,
            $subscriptionId,
            $plan,
            $this->timestamp($payload['startPeriod'] ?? $payload['iyziEventTime'] ?? null),
            $this->timestamp($payload['endPeriod'] ?? null),
        );

        return WebhookStatus::PROCESSED;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function isActivating(array $payload): bool
    {
        $eventType = $this->stringField($payload, 'iyziEventType') ?? $this->stringField($payload, 'eventType');

        if ($eventType !== null && $eventType !== self::EVENT_SUBSCRIPTION_ORDER) {
            return false;
        }

        $status = $this->stringField($payload, 'subscriptionStatus')
            ?? $this->stringField($payload, 'orderStatus')
            ?? $this->stringField($payload, 'status');

        return $status !== null && in_array(strtoupper($status), self::ACTIVATING_STATUSES, strict: true);
    }

    /**
     * The tenant, resolved from the payload.
     *
     * Iyzico has no metadata bag, so the only merchant-controlled field that
     * survives the round trip is `conversationId` — which is where the checkout
     * put its reference. A renewal notification arrives without one, so the
     * second path looks the subscription up by the reference code we recorded
     * on the first activation.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function companyId(array $payload, string $subscriptionId): ?int
    {
        $companyId = CheckoutReference::companyId($payload['conversationId'] ?? null);

        if ($companyId === null) {
            // tenant-scope: bypass-ok webhook arrives pre-tenant; the tenant is being resolved from the payload here, so there is no context to scope by
            $companyId = Subscription::withoutGlobalScopes()
                ->where('provider', IyzicoGateway::PROVIDER)
                ->where('provider_subscription_id', $subscriptionId)
                ->value('company_id');
        }

        if (! is_int($companyId) && ! (is_string($companyId) && ctype_digit($companyId))) {
            return null;
        }

        $companyId = (int) $companyId;

        // Company is exempt from CompanyScope (it is the tenant); an unscoped
        // existence check here is by design, not a bypass.
        return $companyId > 0 && Company::query()->whereKey($companyId)->exists() ? $companyId : null;
    }

    /**
     * Maps the pricing plan reference code back to one of our plan names.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function plan(array $payload): ?string
    {
        $reference = $this->stringField($payload, 'pricingPlanReferenceCode');

        if ($reference === null) {
            return null;
        }

        $configured = config('iyzico.pricing_plans');

        if (! is_array($configured)) {
            return null;
        }

        foreach ($configured as $plan => $code) {
            if (is_string($code) && $code !== '' && hash_equals($code, $reference) && PaidPlans::isPaid($plan)) {
                return (string) $plan;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function stringField(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Iyzico timestamps are epoch milliseconds on some fields and ISO strings
     * on others, so both are accepted and anything else becomes null.
     */
    private function timestamp(mixed $value): ?Carbon
    {
        if (is_int($value) && $value > 0) {
            return Carbon::createFromTimestampMs($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
