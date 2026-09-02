<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped query is built with no tenant in context.
 *
 * Failing closed and loud is deliberate (invariant I1): a scope that silently
 * returns everything is a cross-tenant leak, and one that silently returns
 * nothing is a bug that looks like empty data.
 */
class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No tenant is set in TenantContext while querying [{$model}]. "
            .'Wrap the call in TenantContext::runFor(), or run it behind the '
            .'SetTenantContext middleware.'
        );
    }
}
