<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Settings\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Settings\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\DisposableEmailDomains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Profile and password (docs/contracts/settings-api.md section 1).
 *
 * Both endpoints act on `$request->user()` and take no id, so there is no
 * cross-tenant request to reject in the first place — invariant I1 by
 * construction, the same shape as DELETE /account.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/settings/profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    /**
     * PATCH /api/v1/settings/profile
     *
     * # Why a new address un-verifies the account
     *
     * Spec 7.1 makes a verified mailbox mandatory for the whole tenant surface.
     * If a change of address kept `email_verified_at`, a user — or anyone
     * holding a stolen token — could move the account to a mailbox they do not
     * control and inherit the verified status of the one they proved. The
     * password reset link then goes to the attacker's inbox. So the column is
     * cleared and a fresh verification mail goes out.
     *
     * `email_verification_required` is in the response so the SPA can send the
     * user to the "check your inbox" screen immediately, rather than
     * discovering the state on the next 403 from an unrelated request.
     */
    public function update(UpdateProfileRequest $request, DisposableEmailDomains $disposable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $email = $request->has('email') ? (string) $request->string('email') : null;

        // Same policy and the same code as registration: the message already
        // reads "use your work address", which is the instruction in both
        // cases. A second code would mean a second entry in every catalogue.
        if ($email !== null && $email !== $user->email && $disposable->refuses($email)) {
            throw ApiException::disposableEmail();
        }

        $emailChanged = $email !== null && $email !== $user->email;

        if ($request->has('name')) {
            $user->name = (string) $request->string('name');
        }

        if ($emailChanged) {
            $user->email = $email;
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        $this->audit->record(AuditAction::ProfileUpdated, actor: $user, subject: $user);

        if ($emailChanged) {
            // A separate row, not a flag on the one above: a reviewer asking
            // "when did this account change hands" greps for one action, not
            // for a column inside another.
            $this->audit->record(AuditAction::ProfileEmailChanged, actor: $user, subject: $user);
        }

        return response()->json([
            'user' => new UserResource($user),
            'email_verification_required' => $emailChanged,
        ]);
    }

    /**
     * PATCH /api/v1/settings/password -> 204
     *
     * Every other token is revoked and only the caller's own survives. A
     * password change is the response to a suspected compromise, so the
     * sessions opened with the old credential must not outlive it — and being
     * signed out of the device you are changing it on would be a strange way to
     * confirm the change worked.
     */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // The `hashed` cast on the model is what hashes this; nothing here
        // touches Hash:: directly, so there is one hashing policy in the
        // application and it is the model's.
        $user->update(['password' => (string) $request->string('password')]);

        $current = $user->currentAccessToken();

        $user->tokens()
            ->when(
                $current instanceof Model,
                fn ($query) => $query->whereKeyNot($current->getKey()),
            )
            ->delete();

        $this->audit->record(AuditAction::PasswordChanged, actor: $user, subject: $user);

        return response()->noContent();
    }
}
