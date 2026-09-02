<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            // `text`, not `jsonb`: the `encrypted:array` cast stores a base64
            // ciphertext string, which a jsonb column would reject. Encryption
            // is the harder requirement (invariant I5), so the column type yields.
            $table->text('credentials')->nullable();
            $table->jsonb('settings')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('sync_cursor')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
