<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeToTeam
{
    /**
     * Ensure the authenticated user belongs to a team and share it via request attributes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->team_id) {
            abort(403, 'You must belong to a team to access this resource.');
        }

        $team = $user->team;

        if (! $team) {
            abort(403, 'Team not found. Please contact support.');
        }

        $request->attributes->set('team', $team);

        return $next($request);
    }
}
