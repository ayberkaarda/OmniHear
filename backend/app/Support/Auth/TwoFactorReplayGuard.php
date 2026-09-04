<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

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
 * # Why one conditional UPDATE, and not a read followed by a write
 *
 * W10 kept the mark in the cache and spent it in three application steps: read
 * the mark, compare, write the new one. Two requests carrying the *same* code
 * both read the old mark before either writes, so both compare favourably and
 * both are accepted. The window is a fraction of a millisecond, but an attacker
 * who has an observed code and a challenge token issues both requests at once
 * on purpose — the narrowness of the window is not a defence when the caller
 * chooses the timing. That defeats the entire mechanism above.
 *
 * `spend()` is therefore a single statement:
 *
 *     UPDATE users SET two_factor_last_used_step = :step
 *      WHERE id = :id
 *        AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < :step)
 *
 * PostgreSQL evaluates the predicate and performs the write inside one row lock
 * it takes and releases itself; a concurrent statement queues on that lock and
 * re-evaluates the predicate against the *updated* row. So the affected-row
 * count is the whole answer — one row means this code had not been spent, zero
 * means it had, and exactly one of any number of simultaneous callers can see
 * one. The database decides who was first, not this class.
 *
 * The same predicate is what keeps the mark monotonic: a code from an earlier
 * step cannot un-spend a later one, because `col < :step` is false for it.
 *
 * `tests/Feature/Auth/TwoFactorReplayRaceTest.php` proves this with five forked
 * OS processes on five separate connections, not with a loop.
 *
 * # Why it is a column
 *
 * docs/contracts/w10-two-factor.md asked for it "on the user"; W10 could not
 * write a migration and said so. Atomicity is the reason it moved (a cache mark
 * cannot be compared and written in one operation), and durability is the
 * bonus: a cache eviction or a `FLUSHALL` used to forget which codes had been
 * spent, and to reset the per-token attempt counter and `throttle:public` at
 * the same moment, because all three lived in that one cache. The counter and
 * the limiter are still cache-backed; this mark no longer is.
 *
 * `updated_at` is deliberately left alone. Spending a step is authentication
 * bookkeeping, not a change to the user's profile, and every successful login
 * bumping `users.updated_at` would make that column mean something it does not.
 */
final class TwoFactorReplayGuard
{
    /**
     * Claim a timestep for this user.
     *
     * @return bool true when the step was not spent before and is now; false
     *              when this code has already been used.
     */
    public function spend(User $user, int $step): bool
    {
        // tenant-scope: bypass-ok `users` is a documented CompanyScope
        // exemption (see App\Models\User) and the row is addressed by primary
        // key. Eloquent cannot express a conditional update whose affected-row
        // count is the return value, and that atomicity is the whole point.
        $affected = DB::update(
            <<<'SQL'
            UPDATE users
               SET two_factor_last_used_step = ?
             WHERE id = ?
               AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < ?)
            SQL,
            [$step, $user->getKey(), $step],
        );

        return $affected === 1;
    }

    /**
     * Forget every step this user has spent.
     *
     * Called when the secret those steps belonged to is replaced or removed.
     * Without it, disabling two-factor and immediately re-enrolling meets a
     * mark left by the *old* secret, and the new secret's first code is
     * rejected as a replay of a code it has nothing to do with — a dead zone of
     * up to 90 seconds with no explanation the user could act on.
     */
    public function clear(User $user): void
    {
        // tenant-scope: bypass-ok same reason as spend() above.
        DB::update('UPDATE users SET two_factor_last_used_step = NULL WHERE id = ?', [$user->getKey()]);
    }

    /**
     * The last step this user spent, or null when none is remembered.
     *
     * Read straight from the row rather than from the model instance: `spend()`
     * writes without going through Eloquent, so an in-memory `User` that was
     * loaded before a code was accepted still carries the old value.
     */
    public function lastAcceptedStep(User $user): ?int
    {
        // tenant-scope: bypass-ok same reason as spend() above.
        $step = DB::scalar('SELECT two_factor_last_used_step FROM users WHERE id = ?', [$user->getKey()]);

        return $step === null ? null : (int) $step;
    }
}
