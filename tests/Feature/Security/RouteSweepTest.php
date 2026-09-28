<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Legacy defect D17: a stats route answered without authentication. Here every route requires a
 * session unless it is listed below with the reason it must be public.
 */
const PUBLIC_ROUTES = [
    'up' => 'framework health check for the load balancer',
    'api/health' => 'uptime checks and the post-deploy smoke test',
    'api/plans' => 'the public price list (no internal guards, costs or markup)',
    'api/languages' => 'the public language registry',
    'api/auth/register' => 'sign-up (rate limited, invite-only in the beta)',
    'api/auth/login' => 'sign-in (rate limited)',
    'api/auth/forgot-password' => 'reset request (rate limited, same answer for every address)',
    'api/auth/reset-password' => 'reset with an emailed token (rate limited)',
    'api/auth/verify-email/{id}/{hash}' => 'signed link from the verification email',
    'auth/google/redirect' => 'Google sign-in',
    'auth/google/callback' => 'Google sign-in',
    'sanctum/csrf-cookie' => 'the CSRF cookie the SPA needs before its first POST',
];

/** @return list<RouteDefinition> */
function allRoutes(): array
{
    return array_values(Route::getRoutes()->getRoutes());
}

it('requires a session on every route that is not public by design', function (): void {
    $unprotected = [];

    foreach (allRoutes() as $route) {
        if (array_key_exists($route->uri(), PUBLIC_ROUTES)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $authenticated = collect($middleware)->contains(
            static fn (mixed $m): bool => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:')),
        );
        // API docs: available on a developer machine only (403 everywhere else).
        $developerOnly = in_array(RestrictedDocsAccess::class, $middleware, true);

        if (! $authenticated && ! $developerOnly) {
            $unprotected[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($unprotected)->toBe([]);
});

it('keeps the public list honest: every listed route still exists', function (): void {
    $uris = array_map(static fn (RouteDefinition $route): string => $route->uri(), allRoutes());

    expect(array_values(array_diff(array_keys(PUBLIC_ROUTES), $uris)))->toBe([]);
});

it('answers 401 to anonymous calls on every protected API route', function (): void {
    foreach (allRoutes() as $route) {
        if (array_key_exists($route->uri(), PUBLIC_ROUTES) || ! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $method = collect($route->methods())->first(static fn (string $m): bool => $m !== 'HEAD');

        $this->json((string) $method, $uri)->assertUnauthorized();
    }
});

it('hides the API documentation outside a developer machine', function (): void {
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});
