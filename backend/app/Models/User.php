<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Deliberately exempt from CompanyScope: authentication has to resolve a user
 * before a tenant exists, and a global scope here deadlocks Sanctum token
 * resolution. The boundary is enforced by UserPolicy plus explicit filters on
 * team endpoints. See docs/contracts/backend-core.md section 2.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id', // tenant-scope: bypass-ok User is a documented CompanyScope exemption
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
        'last_login_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            // Encrypted *and* hashed: see App\Support\Auth\RecoveryCodes.
            // The array cast is what makes the column a JSON list of hashes
            // rather than a string the callers have to encode by hand.
            'two_factor_recovery_codes' => 'encrypted:array',
            // The replay high-water mark: the last TOTP timestep this user
            // spent. Hidden as well as cast, because it is a precise statement
            // about when the account last authenticated and about the shape of
            // its second factor, and no client has a use for it.
            // See App\Support\Auth\TwoFactorReplayGuard.
            'two_factor_last_used_step' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return array_search($this->role, $roles, strict: true) !== false;
    }

    public function isOwner(): bool
    {
        return $this->hasRole(self::ROLE_OWNER);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isMember(): bool
    {
        return $this->hasRole(self::ROLE_MEMBER);
    }

    /**
     * True when the user belongs to the same tenant as the given model.
     */
    public function belongsToSameCompany(int $companyId): bool
    {
        return $this->getAttribute('company_id') === $companyId;
    }

    /**
     * Enabled means *confirmed*, never merely started.
     *
     * `filled($this->two_factor_secret)` is the tempting one-liner and it is
     * wrong: a secret exists from the moment enrolment begins, so a user who
     * opened the settings page and closed the tab would be met by a code prompt
     * on their next login, for an authenticator entry they never scanned. There
     * is no way out of that state without support. The server has to have seen
     * a code the user could only have produced from the secret before the
     * second factor becomes a condition of entry — and that is exactly what
     * `two_factor_confirmed_at` records (docs/contracts/w10-two-factor.md).
     */
    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * A secret has been generated but no code has been proven against it yet.
     */
    public function twoFactorPending(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at === null;
    }
}
