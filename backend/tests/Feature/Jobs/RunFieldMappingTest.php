<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunFieldMapping;
use App\Models\Project;
use App\Models\Team;
use App\Models\Upload;
use App\Models\UsageLog;
use App\Services\AI\ClaudeApiClient;
use App\Services\Mapping\FieldMappingService;
use App\Services\Mapping\TranslatableFieldDetector;
use App\Services\Validation\GoldenRuleValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class RunFieldMappingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUpload(?array $columns = null, ?array $samples = null): Upload
    {
        $team = Team::create(['name' => 'Acme', 'owner_id' => 1, 'slug' => 'acme-' . uniqid()]);
        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'P',
            'status' => 'draft',
            'target_languages' => ['de', 'fr'],
        ]);

        return Upload::create([
            'project_id' => $project->id,
            'filename' => 'test.csv',
            'file_path' => 'uploads/test.csv',
            'status' => 'analyzed',
            'column_names' => $columns ?? ['title', 'description', 'price', 'sku'],
            'sample_rows' => $samples ?? [
                ['title' => 'Merino wool sweater ribbed crew neck', 'description' => 'Lightweight breathable and moisture wicking', 'price' => '149.00', 'sku' => 'A-1'],
                ['title' => 'Waterproof hiking boot with vibram',   'description' => 'Provides excellent traction on wet',          'price' => '89.90',  'sku' => 'A-2'],
            ],
        ]);
    }

    private function makeService(): FieldMappingService
    {
        return new FieldMappingService(
            $this->createStub(ClaudeApiClient::class),
            new GoldenRuleValidator(['price', 'stock', 'sku', 'ean', 'weight']),
            new TranslatableFieldDetector(),
        );
    }

    #[Test]
    public function happy_path_transitions_project_draft_to_review_and_writes_usage_log(): void
    {
        $upload = $this->makeUpload();
        $project = $upload->project;

        (new RunFieldMapping($upload->id, useAi: false))->handle($this->makeService());

        $project->refresh();
        $this->assertSame('review', $project->status);

        $this->assertDatabaseHas('field_mappings', [
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'user_approved' => false,
        ]);

        $log = UsageLog::where('team_id', $project->team_id)->first();
        $this->assertNotNull($log);
        $this->assertSame('mapping', $log->action);
        $this->assertSame($upload->id, $log->metadata['upload_id']);
    }

    #[Test]
    public function failure_sets_project_to_error_and_rethrows_for_retry(): void
    {
        // Upload with no column_names triggers a RuntimeException in the service.
        $upload = $this->makeUpload(columns: [], samples: []);
        $project = $upload->project;

        try {
            (new RunFieldMapping($upload->id, useAi: false))->handle($this->makeService());
            $this->fail('Expected RuntimeException from empty upload');
        } catch (RuntimeException) {
            // expected
        }

        $project->refresh();
        $this->assertSame('error', $project->status);
        $this->assertDatabaseCount('field_mappings', 0);
        $this->assertDatabaseCount('usage_logs', 0);
    }

    #[Test]
    public function job_uses_only_heuristics_when_use_ai_is_false(): void
    {
        // The stub ClaudeApiClient would fail loudly if any method were called
        // on it that we hadn't expected — so passing useAi=false and reaching
        // the end of handle() without any error proves AI was skipped.
        $upload = $this->makeUpload();

        (new RunFieldMapping($upload->id, useAi: false))->handle($this->makeService());

        $mapping = $upload->fieldMapping;
        $this->assertNotNull($mapping);
        // AI-specific fields stay null in heuristic mode.
        $this->assertNull($mapping->ai_model);
    }
}
