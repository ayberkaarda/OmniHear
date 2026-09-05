<?php

namespace App\Support\Payments;

use App\Models\WebhookEvent;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Invariant I3, in one place, for both providers.
 *
 * `UNIQUE(webhook_events.event_id)` is what makes a delivery replay-safe, but
 * the index only helps if the insert happens *before* the business logic and
 * the duplicate is treated as success rather than as an error. Both providers
 * therefore funnel through here.
 *
 * Order of operations, and why:
 *
 *  1. Insert the event row, outside any transaction. Postgres aborts the whole
 *     transaction on a constraint violation, so catching the duplicate inside
 *     one would leave us unable to do anything afterwards — including
 *     answering 200.
 *  2. On a duplicate, answer 200 and run nothing. The provider stops retrying,
 *     and the side effect has already happened exactly once.
 *  3. Run the business logic in its own transaction.
 *  4. Stamp `processed_at`.
 *
 * If step 3 throws, the event row is removed again and the exception is
 * rethrown as a 500 so the provider retries. Leaving the row behind would be
 * worse than a duplicate: the retry would be swallowed as a replay and the
 * subscription would never activate. Iyzico gives up after three attempts, so
 * there is no margin for burning one on a delivery we recorded but did not act
 * on.
 *
 * The work is done inline rather than dispatched to a queue job. Two reasons:
 * `app/Jobs/**` belongs to other workstreams in this phase
 * (docs/contracts/wave2-seams.md section 1), and the work itself is one upsert
 * plus one event dispatch — far inside any provider timeout. `$work` is a
 * closure, so moving it behind a job later touches this file and nothing else.
 */
final class WebhookPipeline
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  Closure(): (string|null)  $work  Returns a status string for the
     *                                          response body, or null for the
     *                                          default 'processed'.
     */
    public function process(string $provider, string $eventId, array $payload, Closure $work): JsonResponse
    {
        $event = $this->record($provider, $eventId, $payload);

        if ($event === null) {
            return self::ok(WebhookStatus::DUPLICATE_IGNORED);
        }

        try {
            $status = DB::transaction($work);
        } catch (Throwable $e) {
            // Single row, explicit primary key: the retry must be able to land.
            WebhookEvent::query()->whereKey($event->getKey())->delete();

            throw $e;
        }

        $event->forceFill(['processed_at' => now()])->save();

        return self::ok(is_string($status) ? $status : WebhookStatus::PROCESSED);
    }

    /**
     * The event row, or null when this delivery has been seen before.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function record(string $provider, string $eventId, array $payload): ?WebhookEvent
    {
        try {
            // The insert is wrapped in its own transaction on purpose. Postgres
            // marks the *whole* transaction aborted on a constraint violation —
            // "current transaction is aborted, commands ignored until end of
            // transaction block" — so if an outer transaction is already open,
            // catching the duplicate would leave a connection that can no
            // longer answer anything, including the 200 this method exists to
            // produce. Nested, Laravel issues a SAVEPOINT and rolls back to it,
            // which clears the aborted state and leaves the outer transaction
            // intact. That is not a test-only concern: RefreshDatabase wraps
            // every test in a transaction, but so does any caller that decides
            // to batch deliveries later.
            return DB::transaction(
                // tenant-scope: bypass-ok webhook arrives pre-tenant; webhook_events carries no company_id by design
                fn (): WebhookEvent => WebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'payload' => $payload,
                ]),
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function ok(string $status): JsonResponse
    {
        return response()->json(['status' => $status], 200);
    }
}
