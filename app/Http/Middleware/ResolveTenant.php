<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context for a workspace request.
 *
 * It runs before route-model binding (middleware priority), so a route parameter that belongs to
 * another workspace resolves through the tenant scope and is a plain 404. Membership is checked on
 * every request, so a user removed from a workspace loses access immediately. Staff get no bypass
 * here: in the customer app they act in their own workspace; the admin API uses EnsureStaff.
 */
final readonly class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->current_tenant_id === null || $user->roleIn($user->current_tenant_id) === null) {
            abort(403, 'No workspace is linked to this account.');
        }

        $tenantId = $user->current_tenant_id;

        $this->context->set($tenantId);

        return $next($request);
    }
}
