<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team invitations (docs/contracts/settings-api.md section 2).
 *
 * An invitation is a row, not a user. Creating the account at invite time would
 * put a password-less user inside the tenant — one that already counts as a
 * team member, already resolves through UserPolicy, and can never be told apart
 * from a real one after the fact.
 *
 * UNIQUE(company_id, email) is what keeps a second invitation for the same
 * address from existing: re-inviting refreshes the row it finds rather than
 * stacking tokens that all stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The inviter. Nullable because removing a teammate must not remove
            // the invitations they sent, and the row is still meaningful
            // without an attributable sender.
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role', 20);
            // sha256 of the plaintext token, never the token itself: the row is
            // a credential store and invariant I5's rule applies to it. The
            // plaintext exists only inside the request that created it.
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
