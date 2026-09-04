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
 * What the difference costs, stated plainly: a cache eviction or a flushed
 * Redis forgets the mark, and a code observed inside its own window could then
 * be replayed once. Nothing else about the flow changes — the code still
 * expires on its own within 90 seconds, and the challenge token's attempt
 * counter still bounds guessing. A column would close that residual window and
 * should replace this when the schema is next open.
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
