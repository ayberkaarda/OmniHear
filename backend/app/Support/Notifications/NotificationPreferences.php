<?php

namespace App\Support\Notifications;

use App\Models\Company;

/**
 * Per-company notification channel preferences
 * (docs/contracts/settings-api.md section 4).
 *
 * Stored as `companies.notification_preferences`, a jsonb column that is
 * nullable on purpose: an absent value means every channel is on. The defaults
 * live here rather than in the column so that adding a channel does not need a
 * backfill, and so a company that stored `{"quota_warning":{"mail":false}}`
 * still receives the in-app half.
 *
 * Unknown event keys and unknown channel keys are dropped on write. The stored
 * document is therefore always a subset of what this class declares, which is
 * what keeps `via()` from being handed a channel Laravel cannot resolve.
 */
final class NotificationPreferences
{
    public const QUOTA_WARNING = 'quota_warning';

    /**
     * event => channel => default.
     *
     * @var array<string, array<string, bool>>
     */
    private const DEFAULTS = [
        self::QUOTA_WARNING => [
            'mail' => true,
            'database' => true,
        ],
    ];

    /**
     * @param  array<string, array<string, bool>>  $preferences
     */
    private function __construct(private readonly array $preferences) {}

    public static function forCompany(?Company $company): self
    {
        return self::fromStored($company?->getAttribute('notification_preferences'));
    }

    public static function fromStored(mixed $stored): self
    {
        $stored = is_array($stored) ? $stored : [];
        $merged = [];

        foreach (self::DEFAULTS as $event => $channels) {
            $storedChannels = is_array($stored[$event] ?? null) ? $stored[$event] : [];

            foreach ($channels as $channel => $default) {
                $merged[$event][$channel] = array_key_exists($channel, $storedChannels)
                    ? (bool) $storedChannels[$channel]
                    : $default;
            }
        }

        return new self($merged);
    }

    /**
     * The full, defaults-filled document — the exact shape both
     * `GET` and `PATCH /settings/notifications` return.
     *
     * @return array<string, array<string, bool>>
     */
    public function toArray(): array
    {
        return $this->preferences;
    }

    /**
     * The channels Notification::via() should return for one event.
     *
     * @return list<string>
     */
    public function channelsFor(string $event): array
    {
        $channels = $this->preferences[$event] ?? [];

        return array_values(array_keys(array_filter($channels)));
    }

    /**
     * Validation rules for the PATCH body, derived from the same declaration.
     * A channel added to DEFAULTS becomes writable with no second edit.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::DEFAULTS as $event => $channels) {
            $rules[$event] = ['sometimes', 'array'];

            foreach (array_keys($channels) as $channel) {
                $rules[$event.'.'.$channel] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }
}
