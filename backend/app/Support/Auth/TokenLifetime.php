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
