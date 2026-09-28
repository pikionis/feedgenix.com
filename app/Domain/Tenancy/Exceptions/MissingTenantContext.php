<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/** A tenant-owned model was used without a tenant context: a bug, never a user error. */
final class MissingTenantContext extends LogicException
{
    public static function required(): self
    {
        return new self('No tenant context is set. Run the code inside TenantContext::run() or ::runAsSystem().');
    }

    public static function forModel(string $model): self
    {
        return new self("[{$model}] is tenant-owned but no tenant context is set. Run the code inside TenantContext::run() or ::runAsSystem().");
    }

    public static function forSystemCreate(string $model): self
    {
        return new self("Creating [{$model}] in the system context needs an explicit tenant_id.");
    }
}
