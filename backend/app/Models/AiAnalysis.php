<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AiAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The enums below must stay identical to ai-service/app/schemas.py.
 */
class AiAnalysis extends Model
{
    /** @use HasFactory<AiAnalysisFactory> */
    use BelongsToCompany, HasFactory;

    public const SENTIMENT_LABELS = ['positive', 'neutral', 'negative'];

    public const CATEGORIES = ['complaint', 'praise', 'bug', 'feature_request'];

    protected $table = 'ai_analyses';

    protected $fillable = [
        'feedback_id',
        'sentiment_score',
        'sentiment_label',
        'category',
        'confidence',
        'keywords',
        'model_version',
        'analyzed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sentiment_score' => 'float',
            'confidence' => 'float',
            'keywords' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }
}
