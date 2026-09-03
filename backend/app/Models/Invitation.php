<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending team invitation (docs/contracts/settings-api.md section 2).
 *
 * Tenant-scoped through BelongsToCompany, so another company's invitation is
 * invisible rather than forbidden (invariant I1).
 *
 * `token_hash` is a credential: it is in $hidden, no accessor exposes it, and
 * the plaintext it was derived from is never stored (invariant I5).
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'invited_by',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'accepted_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * The teammate who sent it. Nullable at the database level, because
     * removing a teammate must not remove the invitations they sent.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
