<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MappingController extends Controller
{
    /**
     * Return the latest field mapping for a project.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $mapping = $project->latestMapping;

        if (! $mapping) {
            return response()->json([
                'message' => 'No mapping found for this project. Upload a file first.',
            ], 404);
        }

        return response()->json($mapping);
    }

    /**
     * Update (approve/edit) the field mapping for a project.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $mapping = $project->latestMapping;

        if (! $mapping) {
            return response()->json([
                'message' => 'No mapping found for this project.',
            ], 404);
        }

        $validated = $request->validate([
            'mapping' => ['sometimes', 'array'],
            'translatable_fields' => ['sometimes', 'array'],
            'protected_fields' => ['sometimes', 'array'],
            'skipped_fields' => ['sometimes', 'array'],
            'user_approved' => ['sometimes', 'boolean'],
        ]);

        $mapping->update($validated);

        if ($request->boolean('user_approved')) {
            $mapping->update(['approved_at' => now()]);
            $project->update(['status' => 'review']);
        }

        return response()->json($mapping);
    }
}
