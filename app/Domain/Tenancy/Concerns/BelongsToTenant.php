<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\CrossTenantWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model that holds a workspace's data. Reads are scoped to the current workspace,
 * new rows get its tenant_id, and a row can never be created for, or moved to, another
 * workspace. PostgreSQL row-level security enforces the same rules underneath (ADR 0002).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $context = resolve(TenantContext::class);
            $rowTenant = $model->getAttribute('tenant_id');

            if ($context->isSystem()) {
                if ($rowTenant === null) {
                    throw MissingTenantContext::forSystemCreate($model::class);
                }

                return;
            }

            $tenantId = $context->tenantId() ?? throw MissingTenantContext::forModel($model::class);

            if ($rowTenant === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ((int) $rowTenant !== $tenantId) {
                throw CrossTenantWrite::create($model::class, $tenantId, (int) $rowTenant);
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWrite::move($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
