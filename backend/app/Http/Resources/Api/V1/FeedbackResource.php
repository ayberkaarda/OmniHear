<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feedback row on the wire (docs/contracts/wave2-seams.md section 3).
 *
 * The key list is exhaustive and written out by hand rather than derived from
 * the model, because of one column: **`raw_payload` is never serialized.** It
 * is whatever the platform's API returned - unbounded, unversioned, and full of
 * author PII that this endpoint has no reason to expose. A resource built from
 * `$this->resource->toArray()` would leak it the moment someone added a column.
 *
 * `analysis` is null until analysis_status is `analyzed`.
 *
 * @mixin Feedback
 */
class FeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_id' => $this->integration_id,
            'platform' => $this->integration?->platform,
            'external_id' => $this->external_id,
            'author' => $this->author,
            'body' => $this->body,
            'source_url' => $this->source_url,
            'published_at' => $this->published_at?->toIso8601String(),
            'analysis_status' => $this->analysis_status,
            'analysis' => $this->analysis === null
                ? null
                : (new AiAnalysisResource($this->analysis))->toArray($request),
        ];
    }
}
