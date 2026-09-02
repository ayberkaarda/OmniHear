<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Company is exempt from CompanyScope (it is the tenant), so this policy is the
 * boundary. A company that is not the caller's own is denied *as not found*:
 * a 403 would confirm the row exists (invariant I1).
 */
class CompanyPolicy
{
    public function view(User $user, Company $company): Response
    {
        return $user->belongsToSameCompany($company->id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Company $company): Response
    {
        if (! $user->belongsToSameCompany($company->id)) {
            return Response::denyAsNotFound();
        }

        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Company $company): Response
    {
        if (! $user->belongsToSameCompany($company->id)) {
            return Response::denyAsNotFound();
        }

        return $user->isOwner() ? Response::allow() : Response::deny();
    }
}
