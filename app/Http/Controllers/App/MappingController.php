<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\AI\Exceptions\AiException;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Mapping\Actions\ApproveMapping;
use App\Domain\Mapping\Actions\DraftMapping;
use App\Domain\Mapping\Actions\EditMappingField;
use App\Domain\Mapping\Actions\SuggestMapping;
use App\Domain\Mapping\Exceptions\MappingChanged;
use App\Domain\Mapping\Exceptions\MappingIncomplete;
use App\Domain\Mapping\Models\Mapping;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Requests\App\UpdateMappingFieldRequest;
use App\Http\Resources\MappingResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MappingController
{
    use ActsInWorkspace;

    /**
     * The mapping of the feed's current columns, drafted for free (no AI) on first view.
     *
     * 404 `no_profile` until the feed has a profiled import.
     */
    public function show(Feed $feed, DraftMapping $draft): JsonResponse
    {
        $mapping = $draft->current($feed);

        return $mapping instanceof Mapping ? $this->respond($mapping) : $this->noProfile();
    }

    /**
     * Set one field (owner or admin): a column, a composition, or empty. It is checked like every
     * suggestion: a refused edit is stored as refused, with the reason. 409 on a stale version.
     *
     * @throws MappingChanged
     */
    public function update(UpdateMappingFieldRequest $request, Feed $feed, DraftMapping $draft, EditMappingField $edit): JsonResponse
    {
        $this->authorizeManage($request);
        $mapping = $draft->current($feed);
        if (! $mapping instanceof Mapping) {
            return $this->noProfile();
        }

        try {
            $edit->handle(
                $mapping,
                (int) $request->validated('version'),
                (string) $request->validated('target'),
                $this->nullableString($request->validated('locale')),
                $this->nullableString($request->validated('column')),
                $request->recipe(),
            );
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['target' => $invalid->getMessage()]);
        }

        return $this->respond($mapping->refresh());
    }

    /**
     * Ask the AI for a better mapping (owner or admin), optionally with corrections in words. The
     * worst case is reserved from the workspace's credits first; 409 `insufficient_credits` with
     * the numbers when they do not cover it, 409 `ai_unavailable` when AI is off.
     *
     * @throws InsufficientCredits
     */
    public function suggest(Request $request, Feed $feed, DraftMapping $draft, SuggestMapping $suggest): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            /** Corrections for the AI, in the seller's words. */
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        $mapping = $draft->current($feed);
        if (! $mapping instanceof Mapping) {
            return $this->noProfile();
        }

        try {
            $suggest->queue($mapping, $this->nullableString($data['instructions'] ?? null));
        } catch (AiException $unavailable) {
            return response()->json(['error' => 'ai_unavailable', 'reason' => $unavailable->reason, 'message' => 'AI suggestions are not available right now.'], 409);
        }

        return $this->respond($mapping->refresh(), 202);
    }

    /**
     * Approve the mapping (owner or admin): it needs the SKU and one text field (422 otherwise).
     *
     * @throws MappingChanged
     * @throws MappingIncomplete
     */
    public function approve(Request $request, Feed $feed, DraftMapping $draft, ApproveMapping $approve): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $mapping = $draft->current($feed);
        if (! $mapping instanceof Mapping) {
            return $this->noProfile();
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $this->respond($approve->handle($mapping, (int) $data['version'], $user));
    }

    /** An explicit status: a draft made during this request would otherwise answer 201. */
    private function respond(Mapping $mapping, int $status = 200): JsonResponse
    {
        return (new MappingResource($mapping))->response()->setStatusCode($status);
    }

    private function noProfile(): JsonResponse
    {
        return response()->json(['error' => 'no_profile', 'message' => 'Import the feed first: the mapping is built from its columns.'], 404);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
