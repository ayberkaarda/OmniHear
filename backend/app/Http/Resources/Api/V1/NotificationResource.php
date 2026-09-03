<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One in-app notification (docs/contracts/settings-api.md section 4).
 *
 * `type` is the stored notification class name, which is what Laravel writes
 * into the column and what stays stable across releases. `data` is whatever
 * that notification's toArray() produced — always an object, so the SPA never
 * has to branch on null.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'type' => (string) $this->type,
            'data' => (object) (is_array($this->data) ? $this->data : []),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
