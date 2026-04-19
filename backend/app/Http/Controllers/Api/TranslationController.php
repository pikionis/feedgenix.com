<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunTranslation;
use App\Models\Project;
use App\Models\TranslationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
    /**
     * Start a translation job for the project.
     */
    public function start(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $mapping = $project->latestMapping;

        if (! $mapping || ! $mapping->user_approved) {
            return response()->json([
                'message' => 'Field mapping must be approved before starting translation.',
            ], 422);
        }

        $productsCount = $project->products()->count();

        if ($productsCount === 0) {
            return response()->json([
                'message' => 'No products found. Upload a file first.',
            ], 422);
        }

        $translationJob = TranslationJob::create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'status' => 'queued',
            'total_products' => $productsCount,
            'processed_products' => 0,
            'total_translations' => 0,
            'completed_translations' => 0,
            'cached_translations' => 0,
            'failed_translations' => 0,
            'estimated_cost' => 0,
            'actual_cost' => 0,
        ]);

        $project->update(['status' => 'translating']);

        RunTranslation::dispatch($translationJob->id);

        return response()->json($translationJob, 201);
    }

    /**
     * Return the latest translation job status with progress info.
     */
    public function status(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $job = $project->translationJobs()->latest()->first();

        if (! $job) {
            return response()->json([
                'message' => 'No translation job found for this project.',
            ], 404);
        }

        $progress = $job->total_translations > 0
            ? round(($job->completed_translations / $job->total_translations) * 100, 1)
            : 0;

        return response()->json([
            'job' => $job,
            'progress_percentage' => $progress,
        ]);
    }

    /**
     * Preview the first 10 products with their translations.
     */
    public function preview(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $products = $project->products()
            ->with('translations')
            ->orderBy('row_number')
            ->take(10)
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }
}
