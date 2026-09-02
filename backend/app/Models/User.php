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

    public function twoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret);
    }
}
