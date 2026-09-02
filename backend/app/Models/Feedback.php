<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUS_PENDING = 'pending_analysis';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ANALYZING,
        self::STATUS_ANALYZED,
        self::STATUS_FAILED,
    ];

    protected $table = 'feedbacks';

    protected $fillable = [
        'integration_id',
        'external_id',
        'author',
        'body',
        'source_url',
        'published_at',
        'raw_payload',
        'analysis_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(AiAnalysis::class);
    }
}
