<?php

namespace App\Models;

use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The one table with no company_id — tenant-scope: bypass-ok webhook arrives pre-tenant.
 *
 * A webhook is received before the tenant is known; the tenant is resolved from
 * the payload, so this model deliberately does not use BelongsToCompany.
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    public const PROVIDERS = ['stripe', 'iyzico'];

    protected $fillable = [
        'provider',
        'event_id',
        'payload',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
