<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plan' => $this->plan,
            'analyzed_feedback_count' => $this->analyzed_feedback_count,
            'quota_limit' => $this->quota_limit,
            'quota_remaining' => $this->quotaRemaining(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
