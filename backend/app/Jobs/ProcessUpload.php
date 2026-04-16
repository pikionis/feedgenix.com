<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Upload $upload,
    ) {}

    public function handle(): void
    {
        Log::info("Processing upload {$this->upload->id}");

        $this->upload->update(['status' => 'processing']);

        // TODO: Parse CSV, extract columns, sample rows, update upload record

        $this->upload->update(['status' => 'analyzed']);
    }
}
