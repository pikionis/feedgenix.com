<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Translation\Actions\SaveTranslationPlan;
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
            /** field => translate, keep or skip */
            'fields' => ['present', 'array'],
            'fields.*' => ['string', 'in:translate,keep,skip'],
            /** BCP-47 codes of the workspace's languages. */
            'target_locales' => ['present', 'array', 'max:50'],
            'target_locales.*' => ['string', 'max:16'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            /** @var array<string, string> $fields */
            $fields = array_map(strval(...), (array) $data['fields']);
            $plan = $save->handle($feed, (int) $data['version'], $fields, array_values(array_map(strval(...), (array) $data['target_locales'])), $user);
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['fields' => $invalid->getMessage()]);
        }

        return (new TranslationPlanResource($plan, $feed))->response()->setStatusCode(200);
    }
}
