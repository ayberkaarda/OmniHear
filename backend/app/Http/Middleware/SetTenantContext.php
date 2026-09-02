<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every /api/v1 route: establishes the tenant from
 * the authenticated user and clears it again once the response is built.
 *
 * Not applied to webhook routes, which arrive before a tenant is known.
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

        try {
            return $next($request);
        } finally {
            $this->tenant->set(null);
        }
    }
}
