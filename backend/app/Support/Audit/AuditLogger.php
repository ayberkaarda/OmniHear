<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path into `audit_logs` (spec 5, spec 8).
 *
 * The table shipped in F2 with a model and a factory and nothing writing to it,
 * which is worse than no table at all: it reads as coverage. Everything that
 * has to leave a trail goes through this class, so the action vocabulary, the
 * tenant attribution and the IP capture are decided in exactly one place.
 *
 * Rows are immutable — `AuditLog::UPDATED_AT` is null and nothing here updates.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Write one audit row. Returns null when the event cannot be attributed to
     * a tenant, which is not an error: a failed login for an address that
     * belongs to nobody has no company to file it under, and `company_id` is
     * NOT NULL by design. Those events still reach the structured log at the
     * call site.
     */
    public function record(
        AuditAction $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?int $companyId = null,
        ?string $ip = null,
    ): ?AuditLog {
        $companyId ??= $this->companyIdFor($actor);

        if ($companyId === null) {
            return null;
        }

        $log = new AuditLog;

        // forceFill, not create(): company_id is deliberately absent from
        // $fillable so it can never arrive from a request body. Here it comes
        // from the actor or from the tenant context and from nowhere else.
        $log->forceFill([
            'company_id' => $companyId,
            'user_id' => $this->actorIdFor($actor, $companyId),
            'action' => $action->value,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip' => $ip ?? $this->currentIp(),
        ])->save();

        return $log;
    }

    /**
     * The authenticated user, when there is one and it belongs to the tenant
     * the row is being filed under. Callers in a queue or a webhook get null.
     */
    public function currentActor(?int $companyId = null): ?User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        if ($companyId !== null && ! $user->belongsToSameCompany($companyId)) {
            return null;
        }

        return $user;
    }

    private function companyIdFor(?User $actor): ?int
    {
        $fromActor = $actor?->getAttribute('company_id');

        return $fromActor === null ? $this->tenant->id() : (int) $fromActor;
    }

    /**
     * An actor from another tenant is never written into the row: the audit
     * trail of company A must not name a user of company B (invariant I1).
     */
    private function actorIdFor(?User $actor, int $companyId): ?int
    {
        if ($actor === null || ! $actor->belongsToSameCompany($companyId)) {
            return null;
        }

        return (int) $actor->getKey();
    }

    private function currentIp(): ?string
    {
        $ip = request()?->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
