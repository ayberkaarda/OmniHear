<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('author')->nullable();
            $table->text('body');
            $table->text('source_url')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->jsonb('raw_payload');
            $table->string('analysis_status', 20)->default('pending_analysis');
            $table->timestampsTz();

            // Invariant I2: the same external comment is never analysed twice.
            $table->unique(['integration_id', 'external_id']);
            // Re-queue sweep after a quota upgrade (spec 7.5).
            $table->index(['company_id', 'analysis_status']);
        });

        // Descending index for the inbox listing. The fluent schema builder
        // cannot express a sort direction, so this one is raw DDL.
        DB::statement('CREATE INDEX feedbacks_company_id_published_at_index ON feedbacks (company_id, published_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
