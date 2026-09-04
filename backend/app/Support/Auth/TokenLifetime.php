<?php

namespace App\Support\Auth;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a freshly minted token stops working.
 *
 * `config('sanctum.expiration')` is an absolute ceiling applied to every row in
 * `personal_access_tokens` at once. It is what stops a leaked credential from
 * being valid forever, but it cannot say that a browser session and a CI key
 * deserve different lifetimes — and they do, because they leak differently.
 *
 * Sanctum checks both: a token is valid while it is younger than the ceiling
 * *and* its own `expires_at` is in the future (Laravel\Sanctum\Guard). So the
 * per-kind value below is the one that normally bites, and the ceiling is the
 * backstop for anything minted before this existed or by a path that forgets.
 *
 * A configured value of 0 (or less) means "no per-token expiry" and leaves the
 * ceiling as the only bound — an escape hatch for a deployment that manages
 * rotation some other way, not a default.
 */
final class TokenLifetime
{
    /**
     * Minutes a two-factor challenge token lives
     * (docs/contracts/w10-two-factor.md).
     *
     * A constant rather than a config entry, and deliberately not an escape
     * hatch: unlike the two above it, this number is not a deployment policy
     * about how long a credential may sit in a keychain. It is the width of a
     * half-authenticated state — the password has been accepted and the second
     * factor has not — and a deployment that sets it to zero would turn that
     * state permanent, which is the one value the `> 0` branch below reads as
     * "never expires".
     */
    public const CHALLENGE_MINUTES = 5;

    public static function twoFactorChallenge(): CarbonInterface
    {
        return Carbon::now()->addMinutes(self::CHALLENGE_MINUTES);
    }

    public static function session(): ?CarbonInterface
    {
        return self::after((int) config('sanctum.session_expiration'));
    }

    public static function apiKey(): ?CarbonInterface
    {
        return self::after((int) config('sanctum.api_key_expiration'));
    }

    private static function after(int $minutes): ?CarbonInterface
    {
        return $minutes > 0 ? Carbon::now()->addMinutes($minutes) : null;
    }
}
