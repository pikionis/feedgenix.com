<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    /**
     * Return usage logs for the current billing period, grouped by action.
     */
    public function index(Request $request): JsonResponse
    {
        $team = $request->attributes->get('team');
        $billingPeriod = now()->format('Y-m');

        $usageByAction = UsageLog::where('team_id', $team->id)
            ->where('billing_period', $billingPeriod)
            ->selectRaw('action, SUM(quantity) as total, COUNT(*) as count')
            ->groupBy('action')
            ->get()
            ->keyBy('action');

        $recentLogs = UsageLog::where('team_id', $team->id)
            ->where('billing_period', $billingPeriod)
            ->latest()
            ->take(50)
            ->get();

        $plan = $team->plan ?? 'free';
        $tierConfig = config("feedgenix.tiers.{$plan}", config('feedgenix.tiers.free'));

        return response()->json([
            'billing_period' => $billingPeriod,
            'plan' => $plan,
            'limits' => [
                'products_per_month' => $tierConfig['products_per_month'],
                'target_languages' => $tierConfig['target_languages'],
                'team_members' => $tierConfig['team_members'],
            ],
            'current_usage' => [
                'products_used' => $team->products_used_this_month,
            ],
            'usage_by_action' => $usageByAction,
            'recent_logs' => $recentLogs,
        ]);
    }
}
