<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the pricing page and the upgrade dialog show it: prices, public limits and features.
 * Never the internal guards, costs or the credit markup. Every key is literal, so the OpenAPI
 * contract (and the SPA's types) are exact.
 *
 * @property string $resource the plan key
 */
final class PlanResource extends JsonResource
{
    /** @var string|null */
    public static $wrap;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        $monthly = PlanCatalog::monthlyPrice($plan);
        // Yearly = 12 × the discounted monthly price, paid once for the whole term.
        $yearlyPerMonth = round($monthly * (1 - PlanCatalog::annualDiscount()), 2);
        $perMonth = PlanCatalog::limit($plan, 'products_per_month');

        return [
            'key' => $plan,
            'name' => PlanCatalog::name($plan),
            'tagline' => PlanCatalog::tagline($plan),
            'price_monthly_eur' => $monthly,
            'price_yearly_per_month_eur' => $yearlyPerMonth,
            'price_yearly_eur' => round($yearlyPerMonth * 12, 2),
            'yearly_savings_eur' => round(($monthly - $yearlyPerMonth) * 12, 2),
            'limits' => [
                'feeds' => PlanCatalog::limit($plan, 'feeds'),
                'languages' => PlanCatalog::limit($plan, 'languages'),
                'products_per_month' => $perMonth,
                'products_per_year' => $perMonth === null ? null : $perMonth * 12,
                'worker_slots' => PlanCatalog::limit($plan, 'worker_slots'),
            ],
            'overage_credits_per_product' => PlanCatalog::overageCreditsPerProduct($plan),
            'features' => [
                'ai_translation' => PlanCatalog::feature($plan, 'ai_translation'),
                'ai_agent' => PlanCatalog::feature($plan, 'ai_agent'),
                'rag_memory' => PlanCatalog::feature($plan, 'rag_memory'),
                'field_instructions' => PlanCatalog::feature($plan, 'field_instructions'),
                'model_choice' => PlanCatalog::feature($plan, 'model_choice'),
                'priority_queue' => PlanCatalog::feature($plan, 'priority_queue'),
                'dedicated_infrastructure' => PlanCatalog::feature($plan, 'dedicated_infrastructure'),
                'api_mcp' => PlanCatalog::feature($plan, 'api_mcp'),
            ],
        ];
    }
}
