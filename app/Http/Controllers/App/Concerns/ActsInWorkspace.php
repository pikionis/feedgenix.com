<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use Illuminate\Http\Request;

/** For controllers behind the `tenant` middleware: the workspace in context and the caller's role. */
trait ActsInWorkspace
{
    protected function workspace(): Tenant
    {
        return Tenant::query()->findOrFail(resolve(TenantContext::class)->requireTenantId());
    }

    protected function role(Request $request): string
    {
        $role = $request->attributes->get(ResolveTenant::ROLE_ATTRIBUTE);

        return is_string($role) ? $role : 'member';
    }

    /** Settings and billing are for the owner and admins; members can read. */
    protected function authorizeManage(Request $request): void
    {
        abort_unless(in_array($this->role($request), ['owner', 'admin'], true), 403, 'Only the workspace owner or an admin can change this.');
    }
}
