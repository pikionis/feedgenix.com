<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Return dashboard statistics for the authenticated user's team.
     */
    public function index(Request $request): JsonResponse
    {
        $team = $request->attributes->get('team');
        $billingPeriod = now()->format('Y-m');

        $projectsCount = $team->projects()->count();

        $totalProducts = $team->projects()->sum('products_count');

        $translationsThisMonth = UsageLog::where('team_id', $team->id)
            ->where('billing_period', $billingPeriod)
            ->where('action', 'translation')
            ->sum('quantity');

        $usageThisMonth = UsageLog::where('team_id', $team->id)
            ->where('billing_period', $billingPeriod)
            ->selectRaw('action, SUM(quantity) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $recentProjects = $team->projects()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'status', 'products_count', 'created_at']);

        $plan = $team->plan ?? 'free';
        $tierConfig = config("feedgenix.tiers.{$plan}", config('feedgenix.tiers.free'));

        return response()->json([
            'stats' => [
                'projects_count' => $projectsCount,
                'total_products' => (int) $totalProducts,
                'translations_this_month' => (int) $translationsThisMonth,
                'products_used_this_month' => $team->products_used_this_month,
                'products_limit' => $tierConfig['products_per_month'],
            ],
            'usage_by_action' => $usageThisMonth,
            'recent_projects' => $recentProjects,
            'plan' => $plan,
        ]);
    }
}
