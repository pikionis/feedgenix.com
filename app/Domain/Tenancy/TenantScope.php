<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The first tenancy layer: every query on a tenant-owned model is limited to the current
 * workspace. Without a context it throws instead of returning everything (fail closed).
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = resolve(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        $tenantId = $context->tenantId() ?? throw MissingTenantContext::forModel($model::class);

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
