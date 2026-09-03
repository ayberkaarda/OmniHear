<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User is exempt from CompanyScope (authentication has to find a user before a
 * tenant exists), so this policy carries the isolation for team management.
 *
 * Role matrix — viewAny/view: everyone in the tenant; create/invite: owner and
 * admin, and never at a role above the inviter's own; update: self, or owner
 * and admin for a teammate; changeRole: owner only; delete: owner and admin.
 * Never yourself, and never the last owner (docs/contracts/settings-api.md
 * section 2).
 *
 * # Why the last owner is protected here and not in a controller
 *
 * A company with no owner can never be billed, erased, or have its team managed
 * again: `CompanyPolicy::delete` and `changeRole` below are both owner-only, so
 * the last demotion or removal locks those doors permanently and no endpoint
 * can reopen them. The rule lives in the policy so it holds for every caller,
 * not only the two that happen to exist today.
 */
class UserPolicy
{
    /**
     * Role seniority. Used only to refuse an invitation at a role above the
     * inviter's own — an admin who could invite an owner would have granted
     * themselves a promotion by proxy.
     */
    private const RANK = [
        User::ROLE_MEMBER => 1,
        User::ROLE_ADMIN => 2,
        User::ROLE_OWNER => 3,
    ];

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, User $target): Response
    {
        return $this->sameTenant($user, $target);
    }

    public function create(User $user): Response
    {
        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * `create` plus the seniority rule. Called with the requested role after
     * validation has already established it is one of the three, so an unknown
     * string here is a programming error and is refused rather than ranked.
     */
    public function invite(User $user, string $role): Response
    {
        $allowed = $this->create($user);

        if ($allowed->denied()) {
            return $allowed;
        }

        $requested = self::RANK[$role] ?? PHP_INT_MAX;

        return $requested <= (self::RANK[$user->role] ?? 0)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, User $target): Response
    {
        $tenant = $this->sameTenant($user, $target);

        if ($tenant->denied()) {
            return $tenant;
        }

        if ($user->is($target)) {
            return Response::allow();
        }

        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * PATCH /settings/team/{user}. Separate from `update`, which is the
     * "may edit this user at all" question and deliberately lets a member edit
     * its own profile: changing a *role* is owner-only, never self, and never
     * the last owner.
     */
    public function changeRole(User $user, User $target): Response
    {
        $tenant = $this->sameTenant($user, $target);

        if ($tenant->denied()) {
            return $tenant;
        }

        // "The last owner may not be demoted" needs no separate check on this
        // path, and adding one would be unreachable code rather than a second
        // lock: the caller must be an owner and must not be the target, so a
        // demotable owner always has at least one other owner beside it. The
        // rule is enforced structurally here and explicitly in delete() below,
        // where an admin *can* address the sole owner.
        if ($user->is($target)) {
            return Response::deny();
        }

        return $user->isOwner() ? Response::allow() : Response::deny();
    }

    public function delete(User $user, User $target): Response
    {
        $tenant = $this->sameTenant($user, $target);

        if ($tenant->denied()) {
            return $tenant;
        }

        if ($user->is($target)) {
            return Response::deny();
        }

        if (! $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)) {
            return Response::deny();
        }

        return $this->isLastOwner($target) ? Response::deny() : Response::allow();
    }

    private function sameTenant(User $user, User $target): Response
    {
        return $user->belongsToSameCompany($target->getAttribute('company_id'))
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * True when the target is an owner and the only one left in its company.
     *
     * The explicit company_id filter is the documented consequence of User's
     * CompanyScope exemption (see the class docblock on App\Models\User), not a
     * forgotten scope.
     */
    private function isLastOwner(User $target): bool
    {
        if (! $target->isOwner()) {
            return false;
        }

        return User::query()
            ->where('company_id', $target->getAttribute('company_id'))
            ->where('role', User::ROLE_OWNER)
            ->count() <= 1;
    }
}
