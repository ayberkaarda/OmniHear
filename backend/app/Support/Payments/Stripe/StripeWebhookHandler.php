<?php

namespace App\Support\Payments\Stripe;

use App\Models\Company;
use App\Support\Payments\CheckoutReference;
use App\Support\Payments\PaidPlans;
use App\Support\Payments\SubscriptionActivator;
use App\Support\Payments\WebhookStatus;
use Illuminate\Support\Carbon;

/**
 * Turns a verified Stripe event into an activation, or into a reasoned refusal.
 *
 * Every refusal answers 200 with a status string rather than an error. Stripe
 * retries on 5xx, and none of the conditions below get better on a retry: an
 * event type we do not handle stays unhandled, and a payload naming a company
 * that does not exist will name it again. Answering 200 also keeps junk out of
 * the database — nothing is written except the `webhook_events` row that
 * WebhookPipeline already recorded, which is the audit trail for exactly these
 * cases.
 *
 * Nothing here is logged. The event row carries the whole payload and its
 * `processed_at`; a log line would duplicate it while risking a provider blob
 * with customer PII landing in the log stream (invariant I5).
 */
final class StripeWebhookHandler
{
    public const EVENT_CHECKOUT_COMPLETED = 'checkout.session.completed';

    public function __construct(private readonly SubscriptionActivator $activator) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return string one of WebhookStatus
     */
    public function handle(array $payload): string
    {
        if (($payload['type'] ?? null) !== self::EVENT_CHECKOUT_COMPLETED) {
            return WebhookStatus::IGNORED_UNHANDLED_TYPE;
        }

        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return WebhookStatus::IGNORED_MALFORMED;
        }

        // Stripe marks a session complete before the payment settles when the
        // customer pays by a delayed method; activating then would hand out a
        // paid plan for money that has not arrived.
        if (($object['payment_status'] ?? 'paid') !== 'paid') {
            return WebhookStatus::IGNORED_UNPAID;
        }

        $companyId = $this->companyId($object);

        if ($companyId === null) {
            return WebhookStatus::IGNORED_UNKNOWN_TENANT;
        }

        $plan = $object['metadata']['plan'] ?? null;

        if (! PaidPlans::isPaid($plan)) {
            return WebhookStatus::IGNORED_UNKNOWN_PLAN;
        }

        $subscriptionId = $this->subscriptionId($object);

        if ($subscriptionId === null) {
            return WebhookStatus::IGNORED_MALFORMED;
        }

        $subscription = $this->activator->activate(
            $companyId,
            StripeGateway::PROVIDER,
            $subscriptionId,
            (string) $plan,
            $this->timestamp($object['created'] ?? null),
        );

        // null means the provider subscription id already belongs to another
        // tenant. Decidable, so it answers 200 - a 500 would make the provider
        // retry into the same collision, and iyzico gives up after three.
        if ($subscription === null) {
            return WebhookStatus::IGNORED_UNKNOWN_TENANT;
        }

        return WebhookStatus::PROCESSED;
    }

    /**
     * The tenant, resolved from the payload — a webhook has no authenticated
     * user, so this is the only source there is.
     *
     * `client_reference_id` is preferred because it is the shaped reference we
     * generated at checkout; `metadata.company_id` is the fallback for a
     * session created outside our own checkout call (the Stripe dashboard, a
     * manual recovery). Either way the company must actually exist, or the
     * event is acknowledged and dropped.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function companyId(array $object): ?int
    {
        $companyId = CheckoutReference::companyId($object['client_reference_id'] ?? null);

        if ($companyId === null) {
            $fromMetadata = $object['metadata']['company_id'] ?? null;

            if (is_int($fromMetadata) || (is_string($fromMetadata) && ctype_digit($fromMetadata))) {
                $companyId = (int) $fromMetadata;
            }
        }

        if ($companyId === null || $companyId <= 0) {
            return null;
        }

        // Company is exempt from CompanyScope (it is the tenant), so this is an
        // unscoped read by design, not a bypass.
        return Company::query()->whereKey($companyId)->exists() ? $companyId : null;
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function subscriptionId(array $object): ?string
    {
        // `subscription` is the durable id and is what later lifecycle events
        // carry; the session id is only a usable fallback when Stripe has not
        // attached the subscription yet.
        foreach (['subscription', 'id'] as $key) {
            $value = $object[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return is_int($value) && $value > 0 ? Carbon::createFromTimestampUTC($value) : null;
    }
}
