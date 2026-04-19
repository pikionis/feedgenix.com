<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUpload;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Upload a CSV file for a project. Stores the file, creates an Upload record,
     * and dispatches the ProcessUpload job.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'], // 50MB
        ]);

        $file = $request->file('file');
        $path = $file->store("uploads/{$team->id}/{$project->id}", 'local');

        $upload = $project->uploads()->create([
            'filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'file_size' => $file->getSize(),
            'status' => 'pending',
        ]);

        ProcessUpload::dispatch($upload->id);

        $project->update(['last_upload_at' => now()]);

        return response()->json($upload, 201);
    }
}
