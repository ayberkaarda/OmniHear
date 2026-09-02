<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A company plus its owner, ready to act as a tenant.
 *
 * @return array{0: Company, 1: User}
 */
function tenant(string $role = User::ROLE_OWNER): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->state(['role' => $role])->create();

    return [$company, $user];
}

/**
 * Run a callback with the given company established as the tenant.
 */
function asTenant(Company $company, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($company->id, $callback);
}

/**
 * Register an ad-hoc route under /api/v1 so middleware, policies and the error
 * envelope can be exercised without inventing production endpoints.
 */
function testApiRoute(string $method, string $uri, Closure $handler, array $middleware = []): void
{
    Route::middleware(array_merge(['api'], $middleware))
        ->prefix('api/v1')
        ->group(fn () => Route::{$method}($uri, $handler));
}
