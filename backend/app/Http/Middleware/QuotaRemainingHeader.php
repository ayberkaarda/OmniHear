<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Quota-Remaining on every authenticated /api/v1 response, so the usage meter
 * in the SPA stays fresh without polling (contract section 1).
 *
 * Enforcing the quota (402) is the analysis pipeline's job, not this header's.
 */
class QuotaRemainingHeader
{
    public const HEADER = 'X-Quota-Remaining';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $company = $request->user()?->company;

        if ($company !== null) {
            $response->headers->set(self::HEADER, (string) $company->quotaRemaining());
        }

        return $response;
    }
}
