<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            // No updated_at: audit rows are immutable.
            $table->timestampTz('created_at');
        });

        DB::statement('CREATE INDEX audit_logs_company_id_created_at_index ON audit_logs (company_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
