<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Auth\TokenAbility;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authorization for `/settings/api-keys`
 * (docs/contracts/settings-api.md section 3).
 *
 * Registered explicitly in AuthServiceProvider: Laravel's convention would look
 * for Laravel\Sanctum\Policies\PersonalAccessTokenPolicy, which does not exist.
 *
 * Role matrix — read: everyone in the tenant; create and delete: owner and
 * admin. A member can see which keys exist (they are company infrastructure,
 * and a name plus a last-used timestamp is not a secret) but cannot mint or
 * revoke one.
 *
 * Two things are denied *as not found* rather than as forbidden, both for
 * invariant I1's reason that a 403 confirms a row exists: a key belonging to
 * another company, and a device session addressed through the API-key endpoint.
 * The second is the same boundary from the other side — `/auth/tokens` and
 * `/settings/api-keys` must not be able to revoke each other's rows.
 */
class ApiKeyPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function create(User $user): Response
    {
        return $this->writer($user);
    }

    public function delete(User $user, PersonalAccessToken $token): Response
    {
        $owner = $token->tokenable;

        if (! $owner instanceof User || ! $user->belongsToSameCompany($owner->getAttribute('company_id'))) {
            return Response::denyAsNotFound();
        }

        if (! TokenAbility::isApiKey($token)) {
            return Response::denyAsNotFound();
        }

        return $this->writer($user);
    }

    private function writer(User $user): Response
    {
        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }
}
