<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Settings\InviteTeamMemberRequest;
use App\Http\Requests\Api\V1\Settings\UpdateTeamMemberRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Team management (docs/contracts/settings-api.md section 2).
 *
 * `{user}` is route-model bound, unlike `{integration}` and `{feedback}`. It is
 * safe here for one reason and it is worth stating: User is exempt from
 * CompanyScope, so binding cannot fail closed the way a scoped model would —
 * and since SetTenantContext was prepended to $middlewarePriority ahead of
 * SubstituteBindings (docs/LESSONS.md), the tenant exists by binding time
 * anyway. The isolation is carried by UserPolicy, which denies another
 * company's user *as not found* so the answer is 404 and not 403 (invariant I1).
 */
class TeamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/settings/team — any role.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $paginator = $request->user()->company->users()
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (User $user): array => (new UserResource($user))->resolve())
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/settings/team/invitations -> 201
     *
     * An invitation is a row, never a user: creating the account here would put
     * a password-less member inside the tenant, indistinguishable from a real
     * one and already counted by every team query.
     *
     * A second invitation for an address that already has one *refreshes* the
     * row rather than failing on UNIQUE(company_id, email). Re-inviting is what
     * a user does when the first mail was lost, and stacking rows would leave
     * several tokens valid at once.
     */
    public function invite(InviteTeamMemberRequest $request): JsonResponse
    {
        $role = (string) $request->string('role');

        // After validation, so an unknown role is a 422 on the field rather
        // than a 403 about authority the caller does have.
        Gate::authorize('invite', [User::class, $role]);

        $email = (string) $request->string('email');

        // CompanyScope constrains this: another tenant's invitation for the
        // same address is invisible, so it can neither be found nor collided
        // with (invariant I1).
        $invitation = Invitation::query()->where('email', $email)->first() ?? new Invitation;

        // The plaintext exists only inside this request. What is stored is its
        // SHA-256, so a database copy cannot be replayed as an invitation
        // (invariant I5's rule applied to a new secret).
        $plainToken = Str::random(48);

        $invitation->fill([
            'invited_by' => $request->user()->getKey(),
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays((int) config('registration.invitation_ttl_days', 7)),
            'accepted_at' => null,
        ])->save();

        $this->audit->record(
            AuditAction::TeamInvited,
            actor: $request->user(),
            subject: $invitation,
        );

        // The one moment the plaintext token can be handed on. Sent to an
        // AnonymousNotifiable because the invitee is not a User and must not
        // become one until they accept — and sent *here*, inline, because
        // queuing the notification would serialize the plaintext token into
        // Redis (see the InvitationNotification docblock).
        //
        // An invitation that nothing delivers is a row, an audit entry and a
        // button that lead nowhere: without this line a company can never get
        // a second user, and the whole of spec 8's role model is unreachable
        // in the running product.
        $invitation->refresh()->loadMissing(['company', 'inviter']);

        Notification::route('mail', $email)
            ->notify(InvitationNotification::for($invitation, $plainToken));

        return response()->json(
            ['invitation' => (new InvitationResource($invitation))->resolve()],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /api/v1/settings/team/{user} — owner only.
     *
     * Never yourself and never the last owner; both refusals live in
     * UserPolicy::changeRole, which the form request invokes.
     */
    public function update(UpdateTeamMemberRequest $request, User $user): JsonResponse
    {
        $user->update(['role' => (string) $request->string('role')]);

        $this->audit->record(
            AuditAction::TeamRoleChanged,
            actor: $request->user(),
            subject: $user,
        );

        return response()->json(['user' => new UserResource($user)]);
    }

    /**
     * DELETE /api/v1/settings/team/{user} -> 204 — owner or admin.
     *
     * Tokens are revoked explicitly: Sanctum hangs them off users through a
     * polymorphic pair with no foreign key, so no database cascade reaches
     * them and a removed teammate would keep a working bearer token.
     */
    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete', $user);

        // Written while the row still has an id to name; audit_logs.user_id is
        // nullOnDelete, so the actor survives and the subject id remains.
        $this->audit->record(
            AuditAction::TeamMemberRemoved,
            actor: $request->user(),
            subject: $user,
        );

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 25)));
    }
}
