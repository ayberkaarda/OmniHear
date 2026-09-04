<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor enrolment state.
 *
 * `two_factor_secret` has existed since the tenancy migration (spec section 5
 * lists `2fa_secret` on `users`), but nothing could ever write it. These two
 * columns are what make the feature expressible rather than merely declared.
 *
 * `two_factor_confirmed_at` is the one that carries real weight. Without it,
 * "is 2FA on?" can only be `filled(two_factor_secret)`, which flips to true the
 * moment enrolment *starts* — so a user who scans nothing, or closes the tab
 * between generating a secret and proving they can read a code from it, is
 * locked out of their own account by a half-finished enrolment. Enabled means
 * confirmed, and confirmation means the server has seen a code the user could
 * only have produced from the secret.
 *
 * Recovery codes are stored hashed, as a JSON array of hashes, for the same
 * reason passwords are: this column is the fallback for someone who has lost
 * their authenticator, and a database copy must not hand an attacker a working
 * second factor. They are shown once, in the enrolment response, and never
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_confirmed_at', 'two_factor_recovery_codes']);
        });
    }
};
