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

class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly string $format = 'csv',
    ) {}

    public function handle(): void
    {
        Log::info("Generating {$this->format} export for project {$this->project->id}");

        // TODO: Build export file using CsvExporter (or format-specific exporter),
        //       store to disk, notify user
    }
}
