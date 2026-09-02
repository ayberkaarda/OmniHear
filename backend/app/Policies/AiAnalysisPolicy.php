<?php

namespace App\Policies;

use App\Models\AiAnalysis;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Same reasoning as FeedbackPolicy: CompanyScope is the primary boundary, this
 * is the second one, and a cross-tenant subject is denied as not found so the
 * response cannot confirm that the row exists (invariant I1).
 *
 * ai_analyses carries its own company_id (docs/contracts/backend-core.md
 * section 1) precisely so this check is a column comparison and not a join
 * through the feedback row.
 */
class AiAnalysisPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AiAnalysis $analysis): Response
    {
        return $user->company_id === $analysis->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
