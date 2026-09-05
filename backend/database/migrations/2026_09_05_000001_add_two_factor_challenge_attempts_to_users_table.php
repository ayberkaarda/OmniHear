<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two-factor challenge attempt counter.
 *
 * A challenge token is minted from a password alone; the second factor is the
 * six-digit code that follows. Nothing but a per-account budget bounds how many
 * wrong codes that half-authenticated state may burn: `throttle:public` is keyed
 * by IP and limits how fast *any* caller may knock, not how many guesses one
 * account absorbs, so an attacker with the password and a spread of source
 * addresses can walk the whole code space against a single account without ever
 * meeting the IP limit. This counter is what makes that walk finite.
 *
 * # Why this is a column and not the cache it started in
 *
 * W10 kept the counter in the cache and spent it with `Cache::get` +
 * `Cache::put` — a read-modify-write. Two wrong-code requests in flight at once
 * both read the same count and both write count+1, so the budget never fills:
 * five concurrent guesses advance the counter by one. As a column the increment
 * becomes a single atomic `UPDATE … SET col = COALESCE(col, 0) + 1 … RETURNING`
 * whose returned value is the authority on whether the budget is now spent, and
 * PostgreSQL — not application code — serialises the concurrent increments. This
 * is the same move `two_factor_last_used_step` made, for the same reason; see
 * `App\Support\Auth\TwoFactorReplayGuard` and
 * `App\Support\Auth\TwoFactorChallenge`.
 *
 * It is also per-account rather than per-token. A per-token counter refilled
 * every time a fresh login minted a new token, so the budget an attacker faced
 * reset the moment they re-presented the password they already held. Anchored to
 * the user, the budget survives re-login and is reset only by a successful
 * second factor — a correct code always passes regardless of the count, so a
 * legitimate user is never locked out, while a run of wrong codes stays counted.
 *
 * # Why nullable, and integer
 *
 * Nullable, following `two_factor_last_used_step`: a brand-new user has never
 * been challenged, and null reads as "no failures recorded" — `COALESCE(col, 0)`
 * makes the first increment land on 1 and `reset()` returns the column to 0. A
 * plain `integer` holds the budget (currently 5) with room to spare; the value
 * is bounded by the reset-on-success behaviour and never grows without limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('two_factor_challenge_attempts')->nullable()->after('two_factor_last_used_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_challenge_attempts');
        });
    }
};
