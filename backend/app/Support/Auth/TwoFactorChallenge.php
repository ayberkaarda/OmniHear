<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * # Why the attempt counter is here and not in the limiter
 *
 * `throttle:public` is keyed by IP and bounds how fast anyone can knock. It
 * does not bound how many guesses *one* half-authenticated session gets, and
 * those are different questions: an attacker who has the password and a botnet
 * spends one IP allowance per address while walking the same six-digit space
 * against a single account. The per-token counter is what makes that walk
 * finite — five wrong codes and the token is gone, so the password has to be
 * re-presented (and the login limiter met) to get another one.
 *
 * The counter lives in the cache, as does `throttle:public`, so both reset
 * together if the cache is lost; the bound that survives that is the token's
 * own five-minute `expires_at` in the database. TwoFactorReplayGuard's docblock
 * works through what that leaves exposed.
 */
final class TwoFactorChallenge
{
    /** Wrong codes a single challenge token survives. */
    public const MAX_ATTEMPTS = 5;

    public const TOKEN_NAME = 'two-factor-challenge';

    private const PREFIX = 'two-factor:challenge-attempts:';

    /**
     * Mint the challenge credential for a user whose password checked out.
     */
    public function issue(User $user): string
    {
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
     * Count one wrong code against the token. True when the budget is now
     * spent, in which case the caller destroys the token.
     */
    public function recordFailure(PersonalAccessToken $token): bool
    {
        $key = self::PREFIX.$token->getKey();
        $attempts = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, TokenLifetime::CHALLENGE_MINUTES * 60);

        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Forget the counter for a token that is being destroyed, so a recycled id
     * never inherits a stranger's budget.
     */
    public function forget(PersonalAccessToken $token): void
    {
        Cache::forget(self::PREFIX.$token->getKey());
    }
}
