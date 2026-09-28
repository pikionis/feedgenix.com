<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff admin API. Customers get 403; staff switch to the system context, which sees every
 * workspace and records why (the request path) for the audit trail.
 */
final readonly class EnsureStaff
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_staff, 403, 'This area is for staff only.');

        $this->context->system('staff request: '.$request->method().' '.$request->path());

        return $next($request);
    }
}
