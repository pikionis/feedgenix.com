<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TranslationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunTranslation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly TranslationJob $translationJob,
    ) {}

    public function handle(): void
    {
        Log::info("Running translation job {$this->translationJob->id}");

        $this->translationJob->update(['status' => 'processing', 'started_at' => now()]);

        // TODO: Iterate products, check cache, route translations,
        //       apply golden rule validation, track usage
    }
}
