<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two-factor replay high-water mark.
 *
 * A TOTP code stays arithmetically valid for its whole timestep, widened here
 * to ±1 step (`Totp::WINDOW`), so the same six digits verify for up to ninety
 * seconds. Spending the accepted step — refusing that step and everything
 * below it afterwards — is what stops a code read over a shoulder, off a
 * phishing page or out of a proxy from being used a second time.
 *
 * # Why this is a column and not the cache it started in
 *
 * W10 shipped the mark in the cache, and
 * `docs/contracts/w10-two-factor.md` recorded why: the W10 migration was
 * already applied and no single workstream owned `database/migrations/`. The reason to
 * move it is not durability, it is **atomicity**. A cache mark is read,
 * compared and written by application code, so two requests carrying the same
 * code both pass the comparison before either writes — a challenge token plus
 * one observed code, inside a one-millisecond window, defeats the whole
 * mechanism. As a column the check becomes a single conditional `UPDATE …
 * WHERE col IS NULL OR col < ?` whose affected-row count is the answer, and
 * PostgreSQL — not the application — decides who was first. See
 * `App\Support\Auth\TwoFactorReplayGuard`.
 *
 * Durability comes along for free: an eviction or a `FLUSHALL` no longer
 * forgets which codes have been spent.
 *
 * # Why bigint for a number that is currently small
 *
 * The value stored is `floor(unix_time / Totp::PERIOD)`, so at a thirty-second
 * period it is roughly 5.7×10^7 today and an `integer` would hold it for
 * centuries. `PERIOD` is a constant this codebase can change, though, and at
 * the degenerate end — a one-second step — the mark *is* the Unix timestamp,
 * which overflows a signed 32-bit integer in January 2038. A column that only
 * stays correct while a neighbouring constant does not move is a trap for
 * whoever moves it; four extra bytes on one nullable column is not a cost
 * worth reasoning about.
 *
 * Nullable, and null means "no code has ever been spent by this user" —
 * distinct from step 0, which is a real (if archaeological) step. `store()`
 * and `destroy()` on the two-factor controller reset it to null, because a
 * mark left over from a discarded secret would reject the *new* secret's first
 * code as a replay, for a dead zone of up to ninety seconds that the user has
 * no way to interpret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('two_factor_last_used_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_used_step');
        });
    }
};
