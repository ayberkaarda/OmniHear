<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every /api/v1 route: establishes the tenant from
 * the authenticated user and clears it again once the response is built.
 *
 * Not applied to webhook routes, which arrive before a tenant is known.
 *
 * It also stamps the tenant onto the log context, next to the correlation id
 * CorrelationId already put there (spec 3.6). This is the right seam for it:
 * "which company is this request acting as" is decided here exactly once, and a
 * log line that carries the answer is what makes a tenant's trail queryable.
 * Ids only — never the user's name or address, which are PII (invariant I5).
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $this->tenant->set($user->getAttribute('company_id'));

        Log::shareContext([
            'company_id' => $user->getAttribute('company_id'),
            'user_id' => $user->getAuthIdentifier(),
        ]);

        try {
            return $next($request);
        } finally {
            $this->tenant->set(null);
        }
    }
}
