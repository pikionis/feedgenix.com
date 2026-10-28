<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\TwoFactor\TwoFactorSession;
use App\Domain\Ops\StaffAudit;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff admin API. Customers get 403. Staff need two-factor sign-in set up and proven in this
 * session (it sees every workspace), then switch to the system context, which records why (the
 * request) for the audit trail.
 */
final readonly class EnsureStaff
{
    public function __construct(private TenantContext $context, private StaffAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_staff, 403, 'This area is for staff only.');

        if ($user->two_factor_confirmed_at === null) {
            return response()->json([
                'error' => 'two_factor_setup_required',
                'message' => 'Set up two-factor sign-in to use the staff area.',
            ], 403);
        }

        if (! TwoFactorSession::passed($request)) {
            return response()->json([
                'error' => 'two_factor_required',
                'message' => 'Confirm it is you with a code from your authenticator app.',
            ], 403);
        }

        $this->context->system('staff request: '.$request->method().' '.$request->path());

        // Every staff request leaves one line in the trail, with the answer it got (ADR 0026).
        $response = $next($request);
        $this->audit->record($user, $request, $response->getStatusCode());

        return $response;
    }
}
