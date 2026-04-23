<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessUpload;
use App\Jobs\RunFieldMapping;
use App\Models\Project;
use App\Models\Team;
use App\Models\Upload;
use App\Services\Import\CsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    private function makeUpload(string $csvContents, string $filename = 'products.csv'): Upload
    {
        $team = Team::create(['name' => 'Acme', 'owner_id' => 1, 'slug' => 'acme-' . uniqid()]);
        $project = Project::create(['team_id' => $team->id, 'name' => 'P', 'status' => 'draft']);

        $path = "uploads/{$team->id}/{$project->id}/{$filename}";
        Storage::disk('local')->put($path, $csvContents);

        return Upload::create([
            'project_id' => $project->id,
            'filename' => $filename,
            'file_path' => $path,
            'file_hash' => hash('sha256', $csvContents),
            'file_size' => strlen($csvContents),
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function happy_path_parses_csv_creates_products_and_dispatches_mapping(): void
    {
        $csv = "sku,title,price\nA-1,Merino wool sweater,149.00\nA-2,Waterproof boot,89.90\nA-3,Cotton shirt,29.00\n";
        $upload = $this->makeUpload($csv);

        (new ProcessUpload($upload->id))->handle(new CsvParser());

        $upload->refresh();
        $this->assertSame('analyzed', $upload->status);
        $this->assertSame(['sku', 'title', 'price'], $upload->column_names);
        $this->assertCount(3, $upload->sample_rows);
        $this->assertSame(3, $upload->row_count);
        $this->assertSame(3, $upload->column_count);

        $this->assertDatabaseCount('products', 3);
        Queue::assertPushed(RunFieldMapping::class, fn (RunFieldMapping $j) => $j->uploadId === $upload->id);
    }

    #[Test]
    public function happy_path_populates_external_id_from_a_detected_sku_column(): void
    {
        $csv = "sku,title\nSKU-100,Alpha\nSKU-101,Beta\n";
        $upload = $this->makeUpload($csv);

        (new ProcessUpload($upload->id))->handle(new CsvParser());

        $externalIds = \App\Models\Product::where('project_id', $upload->project_id)
            ->orderBy('row_number')
            ->pluck('external_id')
            ->all();
        $this->assertSame(['SKU-100', 'SKU-101'], $externalIds);
    }

    #[Test]
    public function happy_path_content_hash_is_a_sha256_of_the_row_payload(): void
    {
        $csv = "sku,title\nA-1,Alpha\n";
        $upload = $this->makeUpload($csv);

        (new ProcessUpload($upload->id))->handle(new CsvParser());

        $product = \App\Models\Product::firstWhere('external_id', 'A-1');
        $payload = json_encode(['sku' => 'A-1', 'title' => 'Alpha'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(hash('sha256', $payload), $product->content_hash);
    }

    #[Test]
    public function bad_csv_marks_upload_as_error_and_rethrows(): void
    {
        // An empty file has no header row — the parser throws, and we must
        // persist the error status so the UI can show the user what happened,
        // then re-throw so Laravel's retry/backoff applies.
        $upload = $this->makeUpload('');

        try {
            (new ProcessUpload($upload->id))->handle(new CsvParser());
            $this->fail('Expected exception from malformed CSV');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no header row', $e->getMessage());
        }

        $upload->refresh();
        $this->assertSame('error', $upload->status);
        $this->assertStringContainsString('no header row', $upload->error_message);
        Queue::assertNotPushed(RunFieldMapping::class);
    }

    #[Test]
    public function missing_file_marks_upload_as_error(): void
    {
        $upload = $this->makeUpload("sku,title\nA-1,x\n");
        Storage::disk('local')->delete($upload->file_path);

        try {
            (new ProcessUpload($upload->id))->handle(new CsvParser());
            $this->fail('Expected exception from missing file');
        } catch (\Throwable) {
            // expected
        }

        $upload->refresh();
        $this->assertSame('error', $upload->status);
        Queue::assertNotPushed(RunFieldMapping::class);
    }

    #[Test]
    public function project_products_count_is_updated_after_ingest(): void
    {
        $csv = "sku,title\n" . implode("\n", array_map(fn ($i) => "S-{$i},P-{$i}", range(1, 50))) . "\n";
        $upload = $this->makeUpload($csv);

        (new ProcessUpload($upload->id))->handle(new CsvParser());

        $this->assertSame(50, $upload->project()->first()->products_count);
    }

    #[Test]
    public function upload_without_sku_like_column_still_succeeds_with_null_external_ids(): void
    {
        $csv = "name,description\nA,Alpha\nB,Beta\n";
        $upload = $this->makeUpload($csv);

        (new ProcessUpload($upload->id))->handle(new CsvParser());

        $externalIds = \App\Models\Product::where('project_id', $upload->project_id)
            ->pluck('external_id')
            ->all();
        $this->assertSame([null, null], $externalIds);
    }
}
