<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AiAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `analysis` object nested inside a feedback
 * (docs/contracts/wave2-seams.md section 3).
 *
 * `model_version` is carried on every row on purpose: when the analyzer is
 * retrained the stored scores stop being comparable with new ones, and this
 * field is what lets a re-analysis sweep find the rows that predate the change
 * (see docs/playbooks/ai-contract-sync).
 *
 * @mixin AiAnalysis
 */
class AiAnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sentiment_score' => (float) $this->sentiment_score,
            'sentiment_label' => $this->sentiment_label,
            'category' => $this->category,
            'confidence' => (float) $this->confidence,
            'keywords' => $this->keywords ?? [],
            'model_version' => $this->model_version,
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
        ];
    }
}
