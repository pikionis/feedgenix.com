<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\Upload;
use App\Services\Import\CsvParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Parses a freshly-uploaded CSV:
 *   1. Sniff + read headers and sample rows, persist to the Upload record.
 *   2. Stream every row as a Product, keyed by sku-like column if present,
 *      with a SHA-256 content_hash for cheap change detection on re-uploads.
 *   3. Chain to RunFieldMapping so the project transitions into the mapping stage.
 *
 * Sample rows are used later by FieldMappingService — we keep 20 diverse rows
 * spaced across the file so the AI sees realistic variance, not just the top.
 */
class ProcessUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 3600;

    private const SAMPLE_COUNT = 20;
    private const INSERT_CHUNK = 500;

    public function __construct(
        public readonly int $uploadId,
    ) {}

    public function handle(CsvParser $parser): void
    {
        $upload = Upload::with('project')->findOrFail($this->uploadId);

        Log::info("ProcessUpload starting", ['upload_id' => $upload->id]);
        $upload->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk('local')->path($upload->file_path);

            // Pass 1: preview — headers + first samples, so the UI can show the
            // mapping as soon as analysis finishes even if Phase-B AI is slow.
            $preview = $parser->preview($absolutePath, self::SAMPLE_COUNT);

            $upload->update([
                'column_names' => $preview['headers'],
                'sample_rows' => $preview['samples'],
                'column_count' => $preview['column_count'],
                'row_count' => $preview['row_count'],
            ]);

            // Pass 2: stream every row into the products table.
            $skuColumn = $this->guessSkuColumn($preview['headers']);
            $this->ingestProducts($upload, $parser, $absolutePath, $skuColumn);

            $upload->update(['status' => 'analyzed']);
            $upload->project()->update([
                'products_count' => Product::where('project_id', $upload->project_id)->count(),
            ]);
        } catch (\Throwable $e) {
            $upload->update([
                'status' => 'error',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            Log::error('ProcessUpload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        RunFieldMapping::dispatch($upload->id);

        Log::info("ProcessUpload completed", [
            'upload_id' => $upload->id,
            'rows' => $upload->row_count,
            'columns' => $upload->column_count,
        ]);
    }

    /**
     * Streams rows from the CSV into Product rows, batching inserts for speed.
     * Each product carries its full source_data JSON so translators and the
     * Golden-Rule validator always have the original, untouched values to refer to.
     */
    private function ingestProducts(Upload $upload, CsvParser $parser, string $absolutePath, ?string $skuColumn): void
    {
        $buffer = [];
        $now = now();

        foreach ($parser->rows($absolutePath) as $rowNumber => $row) {
            $payload = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                $payload = '{}';
            }

            $buffer[] = [
                'project_id' => $upload->project_id,
                'external_id' => $skuColumn !== null ? (string) ($row[$skuColumn] ?? '') : null,
                'row_number' => $rowNumber,
                'source_data' => $payload,
                'content_hash' => hash('sha256', $payload),
                'is_new' => true,
                'has_changes' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::INSERT_CHUNK) {
                DB::table('products')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('products')->insert($buffer);
        }
    }

    /**
     * Best-effort SKU column detection. We check common names in a few languages
     * — the Field Mapping AI has the final say, but we need something to populate
     * external_id so re-uploads can match rows by identity.
     */
    private function guessSkuColumn(array $headers): ?string
    {
        $candidates = ['sku', 'code_article', 'product_id', 'product_code', 'reference', 'ref', 'ean', 'id', 'article_number', 'artikelnummer'];
        $normalized = [];
        foreach ($headers as $h) {
            $normalized[mb_strtolower(trim((string) $h))] = $h;
        }
        foreach ($candidates as $c) {
            if (isset($normalized[$c])) {
                return $normalized[$c];
            }
        }
        return null;
    }
}
