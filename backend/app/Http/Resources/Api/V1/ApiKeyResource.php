<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API key as `/settings/api-keys` lists it
 * (docs/contracts/settings-api.md section 3).
 *
 * The `token` column holds the SHA-256 of the credential and is never part of
 * this payload — not hidden from it, never assembled into it (invariant I5).
 * The plaintext exists exactly once, in the 201 body of the create call, and is
 * not stored anywhere at all.
 *
 * @mixin PersonalAccessToken
 */
class ApiKeyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => (string) $this->name,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
