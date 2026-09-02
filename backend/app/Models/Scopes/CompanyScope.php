<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the company in context.
 *
 * Invariant I1. See docs/contracts/backend-core.md section 2.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->has()) {
            throw MissingTenantContextException::forModel($model::class);
        }

        $builder->where($model->getTable().'.company_id', $tenant->id());
    }
}
