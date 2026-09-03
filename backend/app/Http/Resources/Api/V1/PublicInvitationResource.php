<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an *unauthenticated* recipient may learn from a valid invitation token
 * (docs/contracts/settings-api.md section 3a).
 *
 * Deliberately not InvitationResource. That one answers a signed-in teammate
 * managing their own company and carries the row's id and its accepted_at;
 * this one answers whoever holds the token, so it publishes the company **name
 * only** — never its id, never anything else about the tenant. A holder of a
 * leaked token must not be able to learn which tenant they are pointed at
 * beyond the human-readable name they need to decide whether to accept.
 *
 * `token_hash` is absent by construction here as well: this method is an
 * explicit whitelist and the model additionally lists the column in $hidden
 * (invariant I5).
 *
 * @mixin Invitation
 */
class PublicInvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'company_name' => $this->company?->name,
            'role' => $this->role,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
