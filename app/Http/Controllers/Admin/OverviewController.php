<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\Models\TenantLanguage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** The staff overview: platform-wide counts (the system context sees every workspace). */
final class OverviewController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'workspaces' => Tenant::query()->count(),
            'paid_workspaces' => Tenant::query()->where('plan', '!=', 'free')->count(),
            'users' => User::query()->count(),
            'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
            'target_languages' => TenantLanguage::query()->count(),
        ]);
    }
}
