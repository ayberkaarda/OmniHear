<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Defense in depth behind CompanyScope.
 *
 * A cross-tenant read normally never reaches a policy at all: the global scope
 * makes the row invisible and route model binding raises a 404 first. This
 * exists for the paths that bypass the scope - an explicit
 * `withoutGlobalScope`, a relation loaded from an already-resolved parent - and
 * it answers with denyAsNotFound() so the outcome is identical either way.
 *
 * 404, never 403: a 403 confirms the row exists (invariant I1,
 * docs/contracts/http-api-v1.md section 2).
 *
 * Auto-discovered by Laravel's App\Policies\{Model}Policy convention.
 */
class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Feedback $feedback): Response
    {
        return $user->company_id === $feedback->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
