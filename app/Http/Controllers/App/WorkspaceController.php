<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Billing\PlanLimitsApplier;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Requests\App\UpdateWorkspaceLanguagesRequest;
use App\Http\Requests\App\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspaceController
{
    use ActsInWorkspace;

    /** The workspace: plan in force, limits, usage and target languages. */
    public function show(Request $request): WorkspaceResource
    {
        return new WorkspaceResource($this->workspace(), $this->role($request));
    }

    /** Rename the workspace (owner or admin). */
    public function update(UpdateWorkspaceRequest $request): WorkspaceResource
    {
        $this->authorizeManage($request);

        $workspace = $this->workspace();
        $workspace->fill(['name' => trim((string) $request->validated('name'))])->save();

        return new WorkspaceResource($workspace, $this->role($request));
    }

    /**
     * Set the target languages (owner or admin).
     *
     * The list replaces the current one and must fit the plan: otherwise a 402 names the plan that
     * would fit. Concurrent changes are serialised on the workspace row, so two requests can never
     * together exceed the limit.
     *
     * @throws PlanLimitExceeded
     */
    public function updateLanguages(UpdateWorkspaceLanguagesRequest $request, PlanLimitsApplier $limits): WorkspaceResource
    {
        $this->authorizeManage($request);
        $codes = $request->languageCodes();

        $workspace = DB::transaction(function () use ($codes): Tenant {
            $workspace = Tenant::query()->whereKey($this->workspace()->id)->lockForUpdate()->firstOrFail();

            Entitlements::for($workspace)->assertWithin('languages', adding: count($codes), used: 0);

            TenantLanguage::query()->whereNotIn('language_code', $codes)->delete();
            foreach ($codes as $code) {
                TenantLanguage::query()->firstOrCreate(['language_code' => $code]);
            }

            return $workspace;
        });
        // Languages that now fit the plan take translations again.
        $limits->apply($workspace);

        return new WorkspaceResource($workspace, $this->role($request));
    }
}
