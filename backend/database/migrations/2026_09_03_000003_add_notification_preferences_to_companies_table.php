<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company notification preferences (docs/contracts/settings-api.md section 4).
 *
 * On `companies` rather than in a table of its own: the preference is a
 * property of the tenant, there is exactly one row per company by definition,
 * and a separate table would need its own scope, policy and cascade to say the
 * same thing.
 *
 * Nullable with no default — an absent value means "every channel on", which is
 * what App\Support\Notifications\NotificationPreferences fills in. Writing the
 * defaults into every row would freeze today's channel list into the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('notification_preferences')->nullable()->after('quota_limit');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
