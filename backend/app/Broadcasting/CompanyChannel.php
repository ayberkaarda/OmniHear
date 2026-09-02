<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorization for `private-company.{companyId}` (spec 6.5).
 *
 * Invariant I1 on the websocket surface. Everything else in the application
 * gets tenant isolation for free from CompanyScope, but a broadcast channel is
 * outside Eloquent entirely: without this check any authenticated user could
 * subscribe to any company's channel and receive its feedback analyses live.
 *
 * The comparison is on the *authenticated user's* company_id against the
 * channel segment. Nothing here reads the request.
 */
class CompanyChannel
{
    public function join(User $user, int|string $companyId): bool
    {
        // The segment arrives as a string off the wire. A plain (int) cast on
        // it would turn "12-anything" into 12, so the segment has to look like
        // an id before it is compared as one.
        if (! is_int($companyId) && ! ctype_digit($companyId)) {
            return false;
        }

        return $user->company_id !== null && (int) $user->company_id === (int) $companyId;
    }
}
