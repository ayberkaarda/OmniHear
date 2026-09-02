<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: Laravel resolves App\Policies\IntegrationPolicy for
 * App\Models\Integration by convention, so AuthServiceProvider is not touched.
 *
 * CompanyScope already makes another tenant's integration invisible, so the
 * cross-tenant branches here are the second lock rather than the first — they
 * matter for any call that reaches a policy with a model loaded outside a
 * request, and they answer 404 rather than 403 because a 403 confirms the row
 * exists (invariant I1).
 *
 * Role matrix — read: everyone in the tenant; create/update/sync: owner and
 * admin; delete: owner only. Deleting an integration cascades every feedback
 * row and every analysis derived from it, which makes it the most destructive
 * action a tenant has.
 */
class IntegrationPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, Integration $integration): Response
    {
        return $this->sameTenant($user, $integration);
    }

    public function create(User $user): Response
    {
        return $this->writer($user);
    }

    public function update(User $user, Integration $integration): Response
    {
        $tenant = $this->sameTenant($user, $integration);

        return $tenant->denied() ? $tenant : $this->writer($user);
    }

    public function sync(User $user, Integration $integration): Response
    {
        return $this->update($user, $integration);
    }

    public function delete(User $user, Integration $integration): Response
    {
        $tenant = $this->sameTenant($user, $integration);

        if ($tenant->denied()) {
            return $tenant;
        }

        return $user->isOwner() ? Response::allow() : Response::deny();
    }

    private function sameTenant(User $user, Integration $integration): Response
    {
        return $user->belongsToSameCompany($integration->getAttribute('company_id'))
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function writer(User $user): Response
    {
        return $user->hasRole(User::ROLE_OWNER, User::ROLE_ADMIN)
            ? Response::allow()
            : Response::deny();
    }
}
