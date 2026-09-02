<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan', 20)->default('free');
            $table->unsignedBigInteger('analyzed_feedback_count')->default(0);
            $table->unsignedBigInteger('quota_limit')->default((int) config('quota.plans.free.quota_limit'));
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
