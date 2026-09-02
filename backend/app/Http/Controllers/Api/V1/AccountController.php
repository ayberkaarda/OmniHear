<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Right to erasure (spec 8, KVKK/GDPR).
 *
 * `DELETE /api/v1/account` removes the tenant: the company row, and everything
 * the schema cascades from it — users, subscriptions, integrations, feedback,
 * analyses, audit rows.
 *
 * **Cross-tenant is impossible by construction, not by check.** There is no id
 * in the path. The company is read off the authenticated user, so there is no
 * "other company" a caller could name; the policy call below decides *role*,
 * not *tenant*.
 *
 * Synchronous, inside one transaction, even though the response is 202. A
 * queued erasure would answer "your account is gone" and then depend on a Redis
 * job surviving; a lost job leaves personal data alive after the user was told
 * it was destroyed, which is the one failure mode this endpoint exists to
 * prevent. The work is bounded — one DELETE plus the database's own cascades —
 * so there is nothing here worth deferring. The 202 is about what is *not*
 * done: provider-side subscription cancellation and mail-provider suppression
 * are out of scope for this phase, so the request is accepted rather than
 * declared complete end to end.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        // Owner only; admin and member get 403 FORBIDDEN from CompanyPolicy.
        Gate::authorize('delete', $company);

        $companyId = (int) $company->getKey();
        $actorId = (int) $user->getKey();

        DB::transaction(function () use ($company, $companyId, $user): void {
            // Before the deletion, as the brief requires — although the row is
            // then carried off by audit_logs.company_id ON DELETE CASCADE. That
            // is the correct outcome for the *tenant's* trail (erasure means
            // erasure), which is exactly why the durable record of the erasure
            // itself is the structured log line below and not this row.
            $this->audit->record(AuditAction::AccountErased, actor: $user, subject: $company);

            // Sanctum tokens hang off users by a polymorphic pair with no
            // foreign key, so no cascade reaches them. Revoking them here is
            // what actually ends the sessions.
            $tokenOwners = User::query()->where('company_id', $companyId)->pluck('id')->all();

            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->whereIn('tokenable_id', $tokenOwners)
                ->delete();

            $company->delete();
        });

        // The compliance record that outlives the tenant. Ids only: the point
        // of the endpoint is that the personal data is gone (invariant I5).
        Log::info('account.erased', [
            'company_id' => $companyId,
            'user_id' => $actorId,
        ]);

        return response()->json(['message' => __('messages.account_erased')], 202);
    }
}
