<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member')->after('password');
            $table->text('two_factor_secret')->nullable()->after('role');
            $table->string('last_login_ip', 45)->nullable()->after('two_factor_secret');

            $table->index('company_id');
        });

        // The skeleton migration created these as `timestamp without time zone`;
        // the schema contract fixes every timestamp as timestamptz.
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('email_verified_at')->nullable()->change();
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn(['company_id', 'role', 'two_factor_secret', 'last_login_ip']);
        });
    }
};
