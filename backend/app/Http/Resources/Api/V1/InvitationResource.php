<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `token_hash` is absent by construction, not by omission: this method is an
 * explicit whitelist and the model additionally lists the column in $hidden.
 * The plaintext it was derived from is never stored at all (invariant I5).
 *
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
