<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The short-lived credential a correct password buys when a second factor is
 * still owed (docs/contracts/w10-two-factor.md).
 *
 * # Why a token at all
 *
 * The alternative is asking for the password again alongside the code, which
 * means the SPA holds the password across two requests. A challenge token is
 * the narrower thing: it proves the first factor was satisfied, it is valid for
 * five minutes, and `TokenAbility::CHALLENGE` is the only ability it carries —
 * `EnforceTokenAbility` refuses it on every authenticated route, so it opens
 * exactly one door and no other.
 *
 * # Why the attempt counter is per account, in a column, and atomic
 *
 * `throttle:public` is keyed by IP and bounds how fast anyone can knock. It
 * does not bound how many guesses *one* half-authenticated account gets, and
 * those are different questions: an attacker who has the password and a botnet
 * spends one IP allowance per address while walking the same six-digit space
 * against a single account. The attempt counter is what makes that walk finite.
 *
 * Three things about it, each fixing a way the W10 cache version failed:
 *
 *  - **Atomic.** W10 counted with `Cache::get` + `Cache::put`, a
 *    read-modify-write. Two wrong-code requests in flight at once both read the
 *    same count and both write count+1, so five concurrent guesses advanced the
 *    counter by one and the budget never filled. `recordFailure()` is now a
 *    single `UPDATE … SET col = COALESCE(col, 0) + 1 … RETURNING` whose returned
 *    value decides whether the budget is spent — PostgreSQL serialises the
 *    increments, not this class. Same move, same reason as
 *    `TwoFactorReplayGuard`.
 *  - **Per account, not per token.** The count lives on the user, so a fresh
 *    login minting a new token no longer resets it — an attacker re-presenting
 *    the password they already hold does not buy a fresh budget. `issue()` also
 *    revokes any earlier challenge tokens, so a stockpile of them cannot be run
 *    in parallel.
 *  - **Reset only by success.** `reset()` returns the count to zero when a full
 *    session is granted (a correct code or recovery code at the challenge). The
 *    count is never consulted to *block* a correct code — a valid second factor
 *    always passes and clears the count — so a legitimate user who fumbled a few
 *    codes is never locked out, while an attacker's wrong guesses stay counted
 *    across tokens and logins until someone actually authenticates.
 *
 * The counter is a `users` column now, so a cache eviction or a `FLUSHALL` no
 * longer forgets it; the replay mark it sits beside moved for the same reasons.
 */
final class TwoFactorChallenge
{
    /** Wrong codes an account survives before each challenge token dies on its first miss. */
    public const MAX_ATTEMPTS = 5;

    public const TOKEN_NAME = 'two-factor-challenge';

    /**
     * Mint the challenge credential for a user whose password checked out.
     *
     * Any earlier challenge token is revoked first. The per-account attempt
     * counter is deliberately *not* touched here: refilling the budget on every
     * login is exactly the hole this closes, so only a proven second factor
     * (`reset()`) clears it.
     */
    public function issue(User $user): string
    {
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();

        return $user->createToken(
            self::TOKEN_NAME,
            TokenAbility::challenge(),
            TokenLifetime::twoFactorChallenge(),
        )->plainTextToken;
    }

    /**
     * The challenge credential behind a bearer string, or null.
     *
     * The challenge route is public, so Sanctum's guard never runs and the two
     * validity checks it would have made are made here instead: the token's own
     * `expires_at`, and the absolute ceiling `config('sanctum.expiration')`
     * applies to every row in `personal_access_tokens`. Omitting either would
     * make the five-minute lifetime decorative - the row would keep working
     * until somebody deleted it.
     *
     * The ability is matched positively. A token that is not a challenge token
     * is not merely wrong for this route, it is a *session* being replayed
     * against the one endpoint that skips `auth:sanctum`, and answering 401 is
     * the whole of the defence.
     */
    public function resolve(?string $bearer): ?PersonalAccessToken
    {
        if (! is_string($bearer) || trim($bearer) === '') {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearer);

        if (! $token instanceof PersonalAccessToken || ! TokenAbility::isChallenge($token)) {
            return null;
        }

        if ($token->expires_at !== null && Carbon::instance($token->expires_at)->isPast()) {
            return null;
        }

        $ceiling = (int) config('sanctum.expiration');

        if ($ceiling > 0 && $token->created_at !== null
            && Carbon::instance($token->created_at)->lte(Carbon::now()->subMinutes($ceiling))) {
            return null;
        }

        return $token->tokenable instanceof User ? $token : null;
    }

    /**
     * Count one wrong code against the account. True when the budget is now
     * spent, in which case the caller destroys the challenge token.
     *
     * The increment and the read of its result are one statement, so concurrent
     * wrong-code requests cannot collide the way the W10 cache version did: each
     * caller sees its own distinct post-increment value, and exactly the guesses
     * that reach the cap are told the budget is spent.
     */
    public function recordFailure(User $user): bool
    {
        // tenant-scope: bypass-ok `users` is a documented CompanyScope exemption
        // (see App\Models\User) and the row is addressed by primary key. Eloquent
        // cannot express an increment whose post-write value is the return value,
        // and that atomicity is the whole point (see TwoFactorReplayGuard).
        $row = DB::selectOne(
            <<<'SQL'
            UPDATE users
               SET two_factor_challenge_attempts = COALESCE(two_factor_challenge_attempts, 0) + 1
             WHERE id = ?
            RETURNING two_factor_challenge_attempts
            SQL,
            [$user->getKey()],
        );

        // A row that vanished mid-flight cannot be granted more guesses.
        $attempts = $row === null ? self::MAX_ATTEMPTS : (int) $row->two_factor_challenge_attempts;

        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Clear the account's attempt budget after a proven second factor.
     *
     * Called on a successful challenge - the one event that is allowed to refill
     * the budget, because it is the one event an attacker walking the code space
     * cannot manufacture.
     */
    public function reset(User $user): void
    {
        // tenant-scope: bypass-ok same reason as recordFailure() above.
        DB::update('UPDATE users SET two_factor_challenge_attempts = 0 WHERE id = ?', [$user->getKey()]);
    }
}
