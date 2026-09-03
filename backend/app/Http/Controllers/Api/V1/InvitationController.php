<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptInvitationRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\PublicInvitationResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\TokenAbility;
use App\Support\Auth\TokenLifetime;
use App\Support\Http\ApiErrorCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepting a team invitation (docs/contracts/settings-api.md section 3a).
 *
 * Both actions are **public**. They have to be: the recipient has no account,
 * so there is no token to authenticate with and no tenant to scope to. The
 * invitation token in the path is the credential, and it is what selects the
 * tenant — which is why this is the one controller in the application that
 * queries a tenant-owned model with CompanyScope lifted.
 *
 * # Why every failure is the same 404
 *
 * Expired, already accepted and never-existed all answer 404 with the same
 * body. Distinguishing them would let an outsider walk the token space and
 * learn which tokens ever existed and what became of them — the same reasoning
 * that makes cross-tenant access a 404 rather than a 403 (invariant I1):
 * absence and refusal must look identical.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/invitations/{token} -> 200
     *
     * Lets the SPA render who invited whom before asking for a password.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->pending($token);

        return response()->json([
            'invitation' => (new PublicInvitationResource($invitation))->resolve(),
        ]);
    }

    /**
     * POST /api/v1/invitations/{token}/accept -> 201
     *
     * Returns `{token, user, company}` exactly as POST /auth/register does, so
     * the SPA lands in the same authenticated state from either door.
     */
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = $this->pending($token);

        /** @var array{user: User, company: Company, invitation: Invitation} $accepted */
        $accepted = $this->tenant->runFor((int) $invitation->company_id, function () use ($invitation, $request): array {
            return DB::transaction(function () use ($invitation, $request): array {
                // Re-read under a row lock and re-check inside the transaction.
                // Two clicks on the same link arrive as two requests, and the
                // check in pending() happened before either of them had the
                // row: without this, both would pass it and the second would
                // fail on the users.email unique index as a 500.
                $locked = Invitation::query()
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->accepted_at !== null) {
                    throw new ApiException(ApiErrorCode::NotFound);
                }

                // This check is **global**, and it has to be: users.email
                // carries a global UNIQUE index, so a collision in a tenant
                // this request cannot see is still a collision. No
                // withoutGlobalScopes() is needed to get that — User is the
                // documented CompanyScope exemption (authentication has to
                // resolve a user before a tenant exists), so User::query() is
                // already unscoped. Adding the call would read as though a
                // scope were being lifted here and would be a lie.
                //
                // Whatever the outcome, the existing user is never touched:
                // re-pointing an account at the inviting company would be
                // cross-tenant account takeover with extra steps. The
                // invitation is deliberately left open instead — the invitee
                // already having an account is a different problem from a bad
                // token, and closing the row would destroy the only way to fix
                // it.
                if (User::query()->where('email', $locked->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => [__('invitations.email_taken')],
                    ]);
                }

                $user = User::create([
                    'company_id' => $locked->company_id, // tenant-scope: bypass-ok User is a documented CompanyScope exemption; the company comes from the invitation row, never from the request
                    'name' => (string) $request->string('name'),
                    'email' => $locked->email,
                    'password' => (string) $request->string('password'),
                    'role' => $locked->role,
                ]);

                // Created already verified. Reaching this endpoint required a
                // token that was mailed to this address, which is the same
                // proof POST /auth/email/verify asks for; sending a second
                // verification mail to an address that just proved itself would
                // be theatre, and leaving the account unverified would lock the
                // new teammate out of every route behind `verified`.
                $user->forceFill(['email_verified_at' => now()])->save();

                $locked->forceFill(['accepted_at' => now()])->save();

                return [
                    'user' => $user,
                    'company' => $locked->company,
                    'invitation' => $locked,
                ];
            });
        });

        $this->audit->record(
            AuditAction::TeamInvitationAccepted,
            actor: $accepted['user'],
            subject: $accepted['invitation'],
        );

        return response()->json([
            // A device session, not a wildcard token — the same distinction
            // register() and login() make, so /auth/tokens and
            // /settings/api-keys stay disjoint.
            'token' => $accepted['user']->createToken('web', TokenAbility::session(), TokenLifetime::session())->plainTextToken,
            'user' => new UserResource($accepted['user']),
            'company' => new CompanyResource($accepted['company']),
        ], Response::HTTP_CREATED);
    }

    /**
     * The single lookup both actions use: a token that is unknown, expired or
     * already spent produces one indistinguishable 404.
     *
     * Looked up by SHA-256 rather than compared in PHP — the column stores the
     * hash, so the index does the work and no plaintext ever has to be read
     * back out of the database (invariant I5).
     */
    private function pending(string $token): Invitation
    {
        $invitation = Invitation::query()
            ->withoutGlobalScope(CompanyScope::class) // tenant-scope: bypass-ok the caller is unauthenticated and the token IS the tenant selector; every subsequent write runs inside TenantContext::runFor for the company this row names
            ->with('company')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($invitation === null) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $invitation;
    }
}
