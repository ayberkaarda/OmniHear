<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant itself.
 *
 * Deliberately exempt from CompanyScope: scoping a company by its own id is
 * circular. The boundary is enforced by CompanyPolicy and by the fact that the
 * only route reaching a company resolves it from the authenticated user.
 * See docs/contracts/backend-core.md section 2.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'plan',
        'quota_limit',
    ];

    protected function casts(): array
    {
        return [
            'analyzed_feedback_count' => 'integer',
            'quota_limit' => 'integer',
        ];
    }

    /**
     * Remaining analyses, floored at zero. Exposed on every authenticated
     * response as X-Quota-Remaining.
     */
    public function quotaRemaining(): int
    {
        return max(0, $this->quota_limit - $this->analyzed_feedback_count);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
