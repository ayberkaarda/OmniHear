<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User is exempt from CompanyScope (authentication has to find a user before a
 * tenant exists), so this policy carries the isolation for team management.
 *
 * Role matrix — viewAny/view: everyone in the tenant; create/update: owner and
 * admin; delete: owner only, and never yourself.
 */
class UserPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, User $target): Response
    {
        return $user->belongsToSameCompany($target->getAttribute('company_id'))
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, User $target): Response
    {
        if (! $user->belongsToSameCompany($target->getAttribute('company_id'))) {
            return Response::denyAsNotFound();
        }

        if ($user->is($target)) {
            return Response::allow();
        }

        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, User $target): Response
    {
        if (! $user->belongsToSameCompany($target->getAttribute('company_id'))) {
            return Response::denyAsNotFound();
        }

        if ($user->is($target)) {
            return Response::deny();
        }

        return $user->isOwner() ? Response::allow() : Response::deny();
    }
}
