<?php

namespace App\Support\Payments\Iyzico;

/**
 * Derives `webhook_events.event_id` for iyzico.
 *
 * Stripe hands us `evt_…` and the unique index does the rest. Iyzico
 * subscription notifications carry no native event id at all (PROGRESS,
 * verified facts 2026-09-02), so invariant I3 has nothing to key on unless we
 * manufacture one.
 *
 * Scheme
 * ------
 * `iyzico:{eventType}:{sha256(canonical payload)}`
 *
 * The hash input is the decoded payload with every object key sorted
 * recursively and re-encoded — a *canonical* form, not the raw bytes.
 *
 * Why it collapses a replay. Iyzico retries by re-sending the same
 * notification, up to three attempts. Byte-level differences between attempts
 * (key order, whitespace, unicode escaping) are erased by decode-sort-encode,
 * so every attempt of the same notification canonicalises to the same string
 * and therefore to the same id. The second insert hits
 * `UNIQUE(webhook_events.event_id)` and the business logic does not run again.
 *
 * Why distinct events do not collide. The hash covers the *entire* payload, so
 * two notifications collide only if every field matches — including
 * `iyziEventTime`, the subscription and order reference codes, and the status.
 * Any one of those differing produces a different digest; producing a
 * deliberate collision would mean breaking SHA-256. This is strictly stronger
 * than keying on a hand-picked tuple of fields, which silently merges two
 * events that differ only in a field the tuple forgot.
 *
 * The residual assumption, stated plainly: two genuinely distinct events with
 * a *byte-identical* canonical payload are indistinguishable to any derivation
 * scheme whatsoever, and would be treated as a replay. Iyzico stamps
 * `iyziEventTime` on subscription notifications, which is what rules that out
 * in practice.
 *
 * The event type is kept in front of the digest in plain text so a row is
 * readable in `psql` without decoding the payload; it contributes nothing to
 * uniqueness, since it is already inside the hash.
 */
final class IyzicoEventId
{
    private const PREFIX = 'iyzico';

    private const TYPE_MAX_LENGTH = 40;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function derive(array $payload): string
    {
        return sprintf('%s:%s:%s', self::PREFIX, self::eventTypeLabel($payload), self::digest($payload));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function digest(array $payload): string
    {
        return hash('sha256', self::canonicalize($payload));
    }

    /**
     * Deterministic JSON: object keys sorted at every depth, list order kept.
     *
     * List order is meaningful data, so it is never sorted — reordering a list
     * is a different event, not the same one delivered twice.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function canonicalize(array $payload): string
    {
        return (string) json_encode(
            self::sortRecursively($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(static fn (mixed $item): mixed => self::sortRecursively($item), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function eventTypeLabel(array $payload): string
    {
        $type = $payload['iyziEventType'] ?? $payload['eventType'] ?? null;

        if (! is_string($type) || $type === '') {
            return 'unknown';
        }

        $slug = preg_replace('/[^A-Za-z0-9_.-]/', '_', $type) ?? 'unknown';

        return substr($slug, 0, self::TYPE_MAX_LENGTH);
    }
}
