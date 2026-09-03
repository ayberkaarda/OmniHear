<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\User;
use App\Policies\ApiKeyPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        // Sanctum's model lives outside App\Models, so convention-based policy
        // discovery never finds one for it.
        Gate::policy(PersonalAccessToken::class, ApiKeyPolicy::class);

        $this->defineRoleGates();
    }

    /**
     * Role gates for the owner / admin / member hierarchy (spec 8). Each gate
     * grants everything the gates below it grant, so a route guards on the
     * lowest role that may reach it.
     */
    private function defineRoleGates(): void
    {
        Gate::define('act-as-owner', fn (User $user): bool => $user->isOwner());

        Gate::define('act-as-admin', fn (User $user): bool => $user->hasRole(
            User::ROLE_OWNER,
            User::ROLE_ADMIN,
        ));

        Gate::define('act-as-member', fn (User $user): bool => $user->hasRole(
            User::ROLE_OWNER,
            User::ROLE_ADMIN,
            User::ROLE_MEMBER,
        ));
    }
}
