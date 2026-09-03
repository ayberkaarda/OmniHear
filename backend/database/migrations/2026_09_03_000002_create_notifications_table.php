<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's `database` notification channel store (spec 7.3).
 *
 * The 80% quota warning has to reach the user by e-mail *and* in-app;
 * QuotaWarningNotification::via() returned ['mail'] only because there was
 * nowhere to write the in-app half. This is that place.
 *
 * Deliberately not tenant-scoped, and it is on the tenant-scope-guard
 * allowlist for that reason: rows here belong to a *user* through the
 * polymorphic notifiable pair, and every read starts from
 * $request->user()->notifications(), so another user's row is not rejected — it
 * is not in the result set (invariant I1's 404-not-403 rule by construction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
