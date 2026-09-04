<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "This code has already been used."
 *
 * A TOTP code stays arithmetically valid for its whole timestep, and this
 * codebase widens that to ±1 step (Totp::WINDOW) so a slow user still gets in —
 * up to 90 seconds during which the same six digits verify every time. Anyone
 * who reads them over a shoulder, off a phishing page, or out of a proxy can
 * replay them inside that window, which is precisely the attack a second factor
 * is supposed to make expensive. So an accepted step is spent: the next
 * verification refuses that step and everything below it.
 *
 * # Where the high-water mark lives, and why it is not a column
 *
 * docs/contracts/w10-two-factor.md asks for it "on the user". It is in the
 * cache instead, and the reason is mechanical rather than a preference: the
 * W10 migration is written and applied, `backend/database/migrations/` is
 * outside this track's ownership, and a second migration is not this track's to
 * write. This is the one place where the implementation departs from the
 * contract's letter, and it is reported as such rather than left to be found.
 *
 * What the difference costs, stated precisely, because an inaccurate
 * reassurance in a security comment is worse than the gap it describes - the
 * next reader stops looking. If the cache is lost, this mark goes, and so does
 * every other guess-limiting mechanism on the challenge endpoint at the same
 * moment: TwoFactorChallenge's per-token attempt counter is in this same cache,
 * and `throttle:public` is cache-backed too. They do not back each other up;
 * they fail together.
 *
 * What survives a cache loss is exactly two things, both outside the cache: a
 * TOTP code still stops verifying on its own within 90 seconds (Totp::WINDOW),
 * and the challenge token is still a row in `personal_access_tokens` with a
 * five-minute `expires_at` that the database enforces. So the residual exposure
 * is one replay of an observed code, and an attacker's guessing budget reset to
 * full for the remainder of one five-minute token - not unbounded guessing.
 *
 * A column would close the replay half of that and should replace this when the
 * schema is next open (W11). The counter and the limiter would still be cache.
 *
 * The TTL is the longest a code can still verify (the full window, plus a
 * step's slack), so nothing is remembered for longer than it can be abused.
 */
final class TwoFactorReplayGuard
{
    private const PREFIX = 'two-factor:last-step:';

    /**
     * The last step this user spent, or null when none is remembered.
     */
    public function lastAcceptedStep(User $user): ?int
    {
        $step = Cache::get(self::key($user));

        return is_int($step) ? $step : null;
    }

    /**
     * Record a step as spent. Never moves the mark backwards: a code from an
     * earlier step must not un-spend a later one.
     */
    public function markAccepted(User $user, int $step): void
    {
        $current = $this->lastAcceptedStep($user);

        if ($current !== null && $current >= $step) {
            return;
        }

        Cache::put(self::key($user), $step, self::ttlSeconds());
    }

    private static function ttlSeconds(): int
    {
        return Totp::PERIOD * (2 * Totp::WINDOW + 2);
    }

    private static function key(User $user): string
    {
        return self::PREFIX.$user->getKey();
    }
}
