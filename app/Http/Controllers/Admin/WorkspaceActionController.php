<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\SetPlan;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Millicredits;
use App\Domain\Credits\Models\CreditWallet;
use App\Domain\Ops\StaffAudit;
use App\Domain\Ops\Workspaces;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What staff change in a workspace (ADR 0026): credits granted and the plan set by hand until
 * Stripe. Each change describes itself for the staff trail, in credits, never in money.
 */
final readonly class WorkspaceActionController
{
    public function __construct(private TenantContext $context, private Workspaces $workspaces) {}

    /** Grant credits worth an amount in euros (the private-beta AI budget, a goodwill gesture), with why. */
    public function grantCredits(Request $request, string $workspace, GrantCredits $grant): JsonResponse
    {
        $tenant = $this->workspaces->find($workspace);
        $data = $request->validate([
            'euros' => ['required', 'string', 'max:16'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        try {
            $millicredits = Millicredits::parseGrantEuros((string) $data['euros']);
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['euros' => $invalid->getMessage()]);
        }
        $note = trim((string) $data['note']);

        $available = $this->context->run($tenant->id, static function () use ($grant, $millicredits, $note): int {
            $grant->handle($millicredits, 'grant', $note);

            return CreditWallet::query()->firstOrFail()->available();
        });
        StaffAudit::describe($request, 'grant_credits', ['credits' => Millicredits::toCredits($millicredits), 'note' => $note]);

        return response()->json([
            'granted' => Millicredits::toCredits($millicredits),
            'available' => Millicredits::toCredits($available),
        ]);
    }

    /** Put the workspace on a plan; what is over the new limits pauses at once, what fits again resumes. */
    public function setPlan(Request $request, string $workspace, SetPlan $setPlan): JsonResponse
    {
        $tenant = $this->workspaces->find($workspace);
        $data = $request->validate([
            // Which plans exist is SetPlan's to say (PlanCatalog): one list, refused below as a 422.
            'plan' => ['required', 'string', 'max:16'],
            'interval' => ['required', 'string', Rule::in(['month', 'year'])],
            'until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ]);
        $before = ['plan' => Entitlements::for($tenant)->plan(), 'interval' => $tenant->billing_interval, 'ends_at' => $tenant->plan_ends_at?->toDateString()];
        // Validated as Y-m-d above: paid through the end of that day.
        $until = isset($data['until']) ? CarbonImmutable::parse((string) $data['until'])->endOfDay() : null;

        try {
            $applied = $setPlan->handle($tenant, (string) $data['plan'], (string) $data['interval'], $until);
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['plan' => $invalid->getMessage()]);
        }
        $tenant->refresh();
        StaffAudit::describe($request, 'set_plan', [
            'from' => $before,
            'to' => ['plan' => $applied['plan'], 'interval' => $tenant->billing_interval, 'ends_at' => $tenant->plan_ends_at?->toDateString()],
            'paused' => ['feeds' => $applied['feeds_paused'], 'languages' => $applied['languages_paused']],
            'resumed' => ['feeds' => $applied['feeds_resumed'], 'languages' => $applied['languages_resumed']],
        ]);

        return response()->json([
            'plan' => $applied['plan'],
            'plan_name' => PlanCatalog::name($applied['plan']),
            'ends_at' => $tenant->plan_ends_at?->toIso8601String(),
            'feeds_paused' => $applied['feeds_paused'],
            'feeds_resumed' => $applied['feeds_resumed'],
            'languages_paused' => $applied['languages_paused'],
            'languages_resumed' => $applied['languages_resumed'],
        ]);
    }
}
