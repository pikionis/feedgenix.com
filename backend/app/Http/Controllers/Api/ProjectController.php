<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * List all projects for the authenticated user's team, paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $team = $request->attributes->get('team');

        $projects = $team->projects()
            ->latest()
            ->paginate(15);

        return response()->json($projects);
    }

    /**
     * Create a new project for the team.
     */
    public function store(Request $request): JsonResponse
    {
        $team = $request->attributes->get('team');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_language' => ['nullable', 'string', 'max:10'],
            'target_languages' => ['nullable', 'array'],
            'target_languages.*' => ['string', 'max:10'],
        ]);

        $this->ensureTargetLanguagesWithinTier($team, $validated['target_languages'] ?? []);

        $project = $team->projects()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'source_language' => $validated['source_language'] ?? null,
            'target_languages' => $validated['target_languages'] ?? [],
            'status' => 'draft',
        ]);

        return response()->json($project, 201);
    }

    /**
     * Show a single project with its uploads, latest mapping, and translation jobs.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $project->load(['uploads', 'latestMapping', 'translationJobs']);

        return response()->json($project);
    }

    /**
     * Update an existing project.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_language' => ['nullable', 'string', 'max:10'],
            'target_languages' => ['nullable', 'array'],
            'target_languages.*' => ['string', 'max:10'],
        ]);

        if (array_key_exists('target_languages', $validated)) {
            $this->ensureTargetLanguagesWithinTier($team, $validated['target_languages'] ?? []);
        }

        $project->update($validated);

        return response()->json($project);
    }

    /**
     * Reject the request when the merchant tries to set more target languages
     * than their plan allows. Returns a 422 with a Laravel-shaped error so
     * the frontend can render it the same way as any other validation error.
     */
    private function ensureTargetLanguagesWithinTier(\App\Models\Team $team, array $targetLanguages): void
    {
        $plan = $team->plan ?? 'free';
        $limit = (int) (config("feedgenix.tiers.{$plan}.target_languages")
            ?? config('feedgenix.tiers.free.target_languages'));

        // PHP_INT_MAX in config means unlimited (Enterprise).
        if ($limit >= PHP_INT_MAX) {
            return;
        }

        if (count($targetLanguages) > $limit) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'target_languages' => [
                    sprintf(
                        'Your %s plan supports up to %d target language%s. Upgrade to add more.',
                        $plan,
                        $limit,
                        $limit === 1 ? '' : 's',
                    ),
                ],
            ]);
        }
    }

    /**
     * Delete a project.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }
}
