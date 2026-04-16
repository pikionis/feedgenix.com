<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunFieldMapping implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
    ) {}

    public function handle(): void
    {
        Log::info("Running field mapping for project {$this->project->id}");

        // TODO: Call FieldMappingService + TranslatableFieldDetector,
        //       store results in field_mappings table, update project status
    }
}
