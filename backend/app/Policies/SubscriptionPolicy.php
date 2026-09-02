<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered for App\Models\Subscription by naming convention, so it needs
 * no registration in AuthServiceProvider (docs/contracts/wave2-seams.md).
 *
 * Subscription carries CompanyScope, so a cross-tenant row is normally already
 * invisible to a query. This policy is the second lock for the paths where a
 * model arrives from somewhere other than a scoped query — and it denies *as
 * not found*, because a 403 would confirm the row exists (invariant I1).
 */
class SubscriptionPolicy
{
    /**
     * Anyone on the team may see what the company is paying for. Billing state
     * drives the paywall, and a member who cannot see it cannot understand why
     * the application answered 402.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER);
    }

    public function view(User $user, Subscription $subscription): Response
    {
        return $user->belongsToSameCompany($subscription->company_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Starting a checkout commits the company to a recurring charge, so it is
     * the owner's decision alone — not an admin's (spec 8, role hierarchy).
     */
    public function create(User $user): Response
    {
        return $user->isOwner() ? Response::allow() : Response::deny();
    }
}
