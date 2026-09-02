<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape: docs/contracts/wave2-seams.md section 3.
 *
 * `credentials` is absent by construction, not by omission: this method builds
 * an explicit whitelist, and the model additionally lists the column in
 * $hidden. Invariant I5 has no exception for "the owner asked for it" — a
 * secret that has been written is never read back over the wire, in any shape,
 * including immediately after the write that set it.
 *
 * @mixin Integration
 */
class IntegrationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'status' => $this->status,
            // Non-secret connector configuration (app id, locale, marketplace).
            // Always an object so the SPA never has to branch on null.
            'settings' => (object) (is_array($this->settings) ? $this->settings : []),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'sync_error' => $this->sync_error,
            'feedback_count' => (int) ($this->feedbacks_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
