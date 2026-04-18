<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Upload;
use App\Models\UsageLog;
use App\Services\Mapping\FieldMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs AI field mapping for a freshly-analyzed upload.
 *
 * Flow: Upload (analyzed) → FieldMappingService → FieldMapping row.
 * Sets the project status to 'mapping' on start and 'review' on success.
 * On failure the project goes to 'error' and the exception re-raises
 * so Laravel applies normal retry/backoff.
 */
class RunFieldMapping implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Back off 30s, 2m, 5m. */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $uploadId,
        public readonly bool $useAi = true,
    ) {}

    public function handle(FieldMappingService $service): void
    {
        $upload = Upload::with('project')->findOrFail($this->uploadId);
        $project = $upload->project;

        Log::info("RunFieldMapping starting", ['upload_id' => $upload->id, 'project_id' => $project->id]);

        $project->update(['status' => 'mapping']);

        try {
            $mapping = $service->generate($project, $upload, $this->useAi);
        } catch (\Throwable $e) {
            $project->update(['status' => 'error']);
            Log::error('Field mapping failed', [
                'upload_id' => $upload->id,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $project->update(['status' => 'review']);

        UsageLog::create([
            'team_id' => $project->team_id,
            'action' => 'mapping',
            'quantity' => 1,
            'metadata' => [
                'upload_id' => $upload->id,
                'project_id' => $project->id,
                'field_mapping_id' => $mapping->id,
                'ai_model' => $mapping->ai_model,
                'ai_confidence' => $mapping->ai_confidence,
                'translatable_count' => count($mapping->translatable_fields ?? []),
                'protected_count' => count($mapping->protected_fields ?? []),
            ],
            'billing_period' => now()->format('Y-m'),
        ]);

        Log::info("RunFieldMapping completed", [
            'upload_id' => $upload->id,
            'field_mapping_id' => $mapping->id,
        ]);
    }
}
