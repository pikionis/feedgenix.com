<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\Language;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workspace as its members see it: identity, the plan in force (a lapsed paid term shows as
 * Free), limits and usage, and its target languages. Never money: no costs, no markup.
 *
 * @property Tenant $resource
 */
final class WorkspaceResource extends JsonResource
{
    /** @var string|null */
    public static $wrap;

    public function __construct(Tenant $tenant, private readonly string $role)
    {
        parent::__construct($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->resource;
        $entitlements = Entitlements::for($tenant);
        $plan = $entitlements->plan();
        $period = $entitlements->period();

        return [
            'workspace' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'role' => $this->role,
            ],
            'plan' => new PlanResource($plan),
            'recommended_plan' => (string) config('plans.recommended'),
            'entitlements' => [
                'plan' => $plan,
                'interval' => $entitlements->interval(),
                'paid_until' => $entitlements->isPaid() ? $tenant->plan_ends_at?->toIso8601String() : null,
                'period' => [
                    'start' => $period['start']->toIso8601String(),
                    'end' => $period['end']->toIso8601String(),
                ],
                'limits' => [
                    'feeds' => $entitlements->limit('feeds'),
                    'languages' => $entitlements->limit('languages'),
                    'products' => $entitlements->productAllowance(),
                    'worker_slots' => $entitlements->limit('worker_slots'),
                ],
                'used' => [
                    'languages' => $entitlements->used('languages'),
                ],
                'overage' => [
                    'available' => PlanCatalog::overageCreditsPerProduct($plan) !== null,
                    'enabled' => $tenant->overage_enabled,
                    'credits_per_product' => PlanCatalog::overageCreditsPerProduct($plan),
                ],
            ],
            'languages' => LanguageResource::collection($this->languages($tenant)),
        ];
    }

    /** @return Collection<int, Language> */
    private function languages(Tenant $tenant): Collection
    {
        return resolve(TenantContext::class)->run($tenant->id, static fn (): Collection => Language::query()
            ->whereIn('code', TenantLanguage::query()->select('language_code'))
            ->orderBy('code')
            ->get());
    }
}
