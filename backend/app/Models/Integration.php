<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use BelongsToCompany, HasFactory;

    public const PLATFORMS = [
        'appstore',
        'googleplay',
        'zendesk',
        'trustpilot',
        'email',
        'social',
        'fixture',
    ];

    public const STATUSES = ['active', 'error', 'paused'];

    protected $fillable = [
        'platform',
        'credentials',
        'settings',
        'status',
        'last_synced_at',
        'sync_cursor',
        'sync_error',
    ];

    /**
     * Invariant I5: connector secrets never reach a serialized payload.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }
}
