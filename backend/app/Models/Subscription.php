<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToCompany, HasFactory;

    public const PROVIDERS = ['stripe', 'iyzico'];

    protected $fillable = [
        'provider',
        'provider_subscription_id',
        'plan',
        'status',
        'current_period_start',
        'current_period_end',
        'canceled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
