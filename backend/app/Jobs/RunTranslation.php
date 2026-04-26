<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\Team;
use App\Models\Translation;
use App\Models\TranslationJob;
use App\Models\UsageLog;
use App\Services\Translation\TranslationCache;
use App\Services\Translation\TranslationRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Translates every product in a project across all target languages, for every
 * translatable field identified by the approved FieldMapping.
 *
 * Resumable: if the job restarts after a crash, existing (product_id, field_name,
 * target_language) translations are skipped. Checkpoints every N products so
 * progress is visible in the UI.
 *
 * Non-retriable failures (monthly cap reached / all providers exhausted) mark
 * the job as failed immediately — retrying would just hit the same wall.
 */
class RunTranslation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow long-running jobs — large catalogs can take hours. */
    public int $timeout = 7200;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $translationJobId,
    ) {}

    public function handle(TranslationRouter $router, TranslationCache $cache): void
    {
        $job = TranslationJob::with(['project', 'team'])->findOrFail($this->translationJobId);

        if ($job->status === 'completed') {
            Log::info("RunTranslation: job {$job->id} already completed, skipping");
            return;
        }
        if ($job->status === 'cancelled') {
            Log::info("RunTranslation: job {$job->id} was cancelled, skipping");
            return;
        }

        $project = $job->project;
        $team = $job->team;
        $mapping = FieldMapping::where('project_id', $project->id)
            ->where('user_approved', true)
            ->latest('approved_at')
            ->first();

        if (!$mapping) {
            $this->failJob($job, 'No approved field mapping found for this project.');
            return;
        }

        $translatableFields = (array) ($mapping->translatable_fields ?? []);
        $targetLanguages = (array) ($project->target_languages ?? []);
        $sourceLanguage = (string) ($mapping->detected_source_language ?? $project->source_language ?? '');

        if (empty($translatableFields) || empty($targetLanguages) || $sourceLanguage === '') {
            $this->failJob($job, 'Missing translatable fields, target languages, or source language.');
            return;
        }

        $productIds = Product::where('project_id', $project->id)->orderBy('id')->pluck('id')->all();
        $totalProducts = count($productIds);
        $totalTranslations = $totalProducts * count($translatableFields) * count($targetLanguages);

        $job->update([
            'status' => 'processing',
            'started_at' => now(),
            'total_products' => $totalProducts,
            'total_translations' => $totalTranslations,
            'error_message' => null,
        ]);

        $batchSize = (int) config('feedgenix.translation.batch_size', 200);
        $checkpointEvery = (int) config('feedgenix.translation.checkpoint_every', 1000);

        $processed = (int) ($job->processed_products ?? 0);
        $completed = (int) ($job->completed_translations ?? 0);
        $cached = (int) ($job->cached_translations ?? 0);
        $failed = (int) ($job->failed_translations ?? 0);
        $costTotal = (float) ($job->actual_cost ?? 0);
        $sinceCheckpoint = 0;

        // Pass-through pricing rule: a product only counts against the team's
        // monthly quota if at least one of its translations needed an AI call
        // this run. Products served entirely from cache are free.
        // Set keys are product ids → true.
        $billableProductIds = [];

        try {
            foreach (array_chunk($productIds, $batchSize) as $chunkIds) {
                $products = Product::whereIn('id', $chunkIds)->get(['id', 'source_data'])->keyBy('id');

                foreach ($translatableFields as $field) {
                    $sourceTexts = [];
                    $productMap = []; // index → product_id
                    foreach ($chunkIds as $pid) {
                        $product = $products->get($pid);
                        if (!$product) {
                            continue;
                        }
                        $source = $product->source_data[$field] ?? null;
                        $source = is_string($source) ? trim($source) : '';
                        if ($source === '') {
                            continue;
                        }
                        $productMap[] = $pid;
                        $sourceTexts[] = $source;
                    }

                    if ($sourceTexts === []) {
                        continue;
                    }

                    foreach ($targetLanguages as $targetLang) {
                        $existingIds = Translation::whereIn('product_id', $productMap)
                            ->where('field_name', $field)
                            ->where('target_language', $targetLang)
                            ->pluck('product_id')
                            ->all();
                        $existing = array_flip($existingIds);

                        $toTranslate = [];
                        $toTranslateProducts = [];
                        foreach ($productMap as $idx => $pid) {
                            if (isset($existing[$pid])) {
                                continue;
                            }
                            $toTranslate[] = $sourceTexts[$idx];
                            $toTranslateProducts[] = $pid;
                        }

                        if ($toTranslate === []) {
                            continue;
                        }

                        $fieldType = $this->inferFieldType($field);
                        $batch = $router->translateBatch(
                            $toTranslate,
                            $sourceLanguage,
                            (string) $targetLang,
                            (int) $team->id,
                            $fieldType,
                        );

                        DB::transaction(function () use ($batch, $toTranslate, $toTranslateProducts, $field, $targetLang, $cache, &$billableProductIds) {
                            foreach ($batch['results'] as $i => $result) {
                                $cache->put(
                                    productId: $toTranslateProducts[$i],
                                    fieldName: $field,
                                    sourceText: $toTranslate[$i],
                                    targetLanguage: (string) $targetLang,
                                    translatedText: (string) $result['text'],
                                    provider: (string) $result['provider'],
                                    isCached: (bool) $result['cached'],
                                );
                                // Mark the product billable iff this specific
                                // translation needed an AI call (cache miss).
                                if (!(bool) $result['cached']) {
                                    $billableProductIds[$toTranslateProducts[$i]] = true;
                                }
                            }
                        });

                        $completed += count($batch['results']);
                        $cached += (int) $batch['stats']['cached'];
                        $costTotal += (float) $batch['stats']['cost_eur'];
                    }
                }

                $processed += count($chunkIds);
                $sinceCheckpoint += count($chunkIds);

                if ($sinceCheckpoint >= $checkpointEvery) {
                    $this->checkpoint($job, $processed, $completed, $cached, $failed, $costTotal);
                    $sinceCheckpoint = 0;
                }
            }
        } catch (RuntimeException $e) {
            // Monthly cap / exhausted providers — don't retry, just fail.
            $this->checkpoint($job, $processed, $completed, $cached, $failed, $costTotal);
            $this->failJob($job, $e->getMessage(), retry: false);
            return;
        } catch (\Throwable $e) {
            // Other errors: persist checkpoint, then throw for Laravel retry/backoff.
            $this->checkpoint($job, $processed, $completed, $cached, $failed, $costTotal);
            throw $e;
        }

        $job->update([
            'status' => 'completed',
            'completed_at' => now(),
            'processed_products' => $processed,
            'completed_translations' => $completed,
            'cached_translations' => $cached,
            'failed_translations' => $failed,
            'actual_cost' => $costTotal,
        ]);

        $project->update(['status' => 'completed']);

        // Charge the quota only for products that actually consumed an AI
        // call this run. Cache-only products are free under pass-through pricing.
        $billableCount = count($billableProductIds);
        $this->incrementTeamUsage($team, $billableCount);

        UsageLog::create([
            'team_id' => $team->id,
            'action' => 'translation',
            // `quantity` reflects what we charged for, not what we processed.
            'quantity' => $billableCount,
            'metadata' => [
                'project_id' => $project->id,
                'translation_job_id' => $job->id,
                'products_processed' => $processed,
                'products_billable' => $billableCount,
                'translations' => $completed,
                'cached' => $cached,
                'cost_eur' => round($costTotal, 4),
                'target_languages' => $targetLanguages,
                'fields' => $translatableFields,
            ],
            'billing_period' => now()->format('Y-m'),
        ]);

        Log::info("RunTranslation completed", [
            'translation_job_id' => $job->id,
            'products_processed' => $processed,
            'products_billable' => $billableCount,
            'translations' => $completed,
            'cached' => $cached,
            'cost_eur' => round($costTotal, 4),
        ]);
    }

    private function checkpoint(TranslationJob $job, int $processed, int $completed, int $cached, int $failed, float $cost): void
    {
        $job->update([
            'processed_products' => $processed,
            'completed_translations' => $completed,
            'cached_translations' => $cached,
            'failed_translations' => $failed,
            'actual_cost' => $cost,
        ]);
    }

    private function failJob(TranslationJob $job, string $message, bool $retry = false): void
    {
        $job->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $message,
        ]);
        Log::warning("RunTranslation failed", ['job_id' => $job->id, 'message' => $message, 'retry' => $retry]);
    }

    /**
     * Most catalogs don't have category-style fields, but when they do we want
     * the provider to preserve hierarchy separators. This heuristic is enough
     * for now — callers can override with a mapping setting later.
     */
    private function inferFieldType(string $fieldName): string
    {
        $lower = mb_strtolower($fieldName);
        if (str_contains($lower, 'categor') || str_contains($lower, 'kategori')) {
            return 'category';
        }
        return 'default';
    }

    private function incrementTeamUsage(Team $team, int $productsProcessed): void
    {
        $team->increment('products_used_this_month', $productsProcessed);
    }
}
