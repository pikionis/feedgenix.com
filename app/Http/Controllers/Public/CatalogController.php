<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Billing\PlanCatalog;
use App\Domain\Translation\Models\Language;
use App\Http\Resources\LanguageResource;
use App\Http\Resources\PlanResource;
use Illuminate\Http\JsonResponse;

/** Public reference data for the pricing page and the sign-up flow. */
final class CatalogController
{
    /**
     * The plans.
     *
     * Prices, public limits and features only — never internal guards, costs or the credit markup.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'recommended' => (string) config('plans.recommended'),
            'annual_discount' => PlanCatalog::annualDiscount(),
            'plans' => PlanResource::collection(PlanCatalog::LADDER),
        ]);
    }

    /** The languages a workspace can translate into (BCP-47). */
    public function languages(): JsonResponse
    {
        return response()->json([
            'languages' => LanguageResource::collection(Language::query()->where('active', true)->orderBy('name')->get()),
        ]);
    }
}
