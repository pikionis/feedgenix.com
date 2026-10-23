<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Catalog\Actions\StartCatalogSync;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Mapping\Actions\DraftMapping;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Translation\Actions\SaveTranslationPlan;
use App\Domain\Translation\Actions\StartTranslation;
use App\Domain\Translation\Exceptions\PlanChanged;
use App\Domain\Translation\TranslationPlanner;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Resources\TranslationPlanResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TranslationPlanController
{
    use ActsInWorkspace;

    /** What the feed will translate, into which languages, how much work that is and its estimate in credits. */
    public function show(Feed $feed, TranslationPlanner $planner): JsonResponse
    {
        return (new TranslationPlanResource($planner->planOf($feed), $feed))->response()->setStatusCode(200);
    }

    /**
     * Choose each text field's mode and the target languages (owner or admin). Languages must be
     * the workspace's own; 409 on a stale version.
     *
     * @throws PlanChanged
     */
    public function update(Request $request, Feed $feed, SaveTranslationPlan $save): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            /**
             * field => translate, keep or skip
             *
             * @var array<string, 'translate'|'keep'|'skip'>
             */
            'fields' => ['present', 'array'],
            'fields.*' => ['string', 'in:translate,keep,skip'],
            /** BCP-47 codes of the workspace's languages. */
            'target_locales' => ['present', 'array', 'max:50'],
            'target_locales.*' => ['string', 'max:16'],
            /** Translate the new and changed texts of each new file without waiting for a person. Omitted: unchanged. */
            'auto_translate' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            /** @var array<string, string> $fields */
            $fields = array_map(strval(...), (array) $data['fields']);
            $plan = $save->handle($feed, (int) $data['version'], $fields, array_values(array_map(strval(...), (array) $data['target_locales'])), $user, isset($data['auto_translate']) ? (bool) $data['auto_translate'] : null);
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['fields' => $invalid->getMessage()]);
        }

        return (new TranslationPlanResource($plan, $feed))->response()->setStatusCode(200);
    }

    /**
     * Start translating the plan as shown (owner or admin): kept fields and the memory's repeats
     * are written at once; the rest is queued as AI work, within the plan's allowance. 409 on a
     * stale version.
     *
     * @throws PlanChanged
     */
    public function start(Request $request, Feed $feed, StartTranslation $start, TranslationPlanner $planner): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $start->handle($feed, (int) $data['version']);

        return (new TranslationPlanResource($planner->planOf($feed), $feed))->response()->setStatusCode(202);
    }

    /**
     * Read the feed's current file into the catalogue again (owner or admin): after a read that
     * failed, or for a mapping approved before the catalogue existed. A run still working is left
     * to finish. 409 when the current columns have no approved mapping.
     */
    public function sync(Request $request, Feed $feed, DraftMapping $drafts, StartCatalogSync $start, TranslationPlanner $planner): JsonResponse
    {
        $this->authorizeManage($request);
        $mapping = Mapping::query()->find($drafts->current($feed)?->id);
        if (! $mapping instanceof Mapping || ! $mapping->isApproved()) {
            return response()->json(['error' => 'mapping_not_approved', 'message' => 'Approve the mapping first: the products are read through it.'], 409);
        }
        $start->forMapping($mapping);

        return (new TranslationPlanResource($planner->planOf($feed), $feed))->response()->setStatusCode(202);
    }
}
