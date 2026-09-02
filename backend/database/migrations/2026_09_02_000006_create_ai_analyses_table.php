<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            // Explicit table name: the inflector treats "feedback" as
            // uncountable, so constrained() would look for a `feedback` table.
            $table->foreignId('feedback_id')->unique()->constrained('feedbacks')->cascadeOnDelete();
            // company_id is carried directly so the global scope stays a plain
            // WHERE instead of a join (invariant I1).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('sentiment_score', 4, 3);
            $table->string('sentiment_label', 10);
            $table->string('category', 20);
            $table->decimal('confidence', 4, 3);
            $table->jsonb('keywords');
            $table->string('model_version', 50);
            $table->timestampTz('analyzed_at');
            $table->timestampsTz();

            $table->index(['company_id', 'sentiment_label']);
        });

        DB::statement('ALTER TABLE ai_analyses ADD CONSTRAINT ai_analyses_sentiment_score_check CHECK (sentiment_score >= -1 AND sentiment_score <= 1)');
        DB::statement('ALTER TABLE ai_analyses ADD CONSTRAINT ai_analyses_confidence_check CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
