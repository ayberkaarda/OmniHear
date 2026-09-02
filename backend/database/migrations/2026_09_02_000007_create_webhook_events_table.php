<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tenant-scope: bypass-ok webhook arrives pre-tenant; the tenant is resolved from the payload
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            // Invariant I3: replay / duplicate protection.
            $table->string('event_id')->unique();
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['provider', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
