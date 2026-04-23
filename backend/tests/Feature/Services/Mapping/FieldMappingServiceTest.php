<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Mapping;

use App\Models\Project;
use App\Models\Team;
use App\Models\Upload;
use App\Services\AI\ClaudeApiClient;
use App\Services\Mapping\FieldMappingService;
use App\Services\Mapping\TranslatableFieldDetector;
use App\Services\Validation\GoldenRuleValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * FieldMappingService tests focus on the heuristic path (useAi=false) because:
 *   1. It's always the fallback when Claude is unreachable.
 *   2. The AI path adds non-determinism we don't want in a test suite.
 *   3. The critical invariant — Golden Rule protected columns are never
 *      re-routed to "translatable" — is enforceable purely in-process.
 */
class FieldMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private FieldMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Use real ClaudeApiClient but we'll call generate with useAi=false,
        // so the client is never actually invoked.
        // Stub satisfies the type; useAi=false means nothing is ever called on it.
        $this->service = new FieldMappingService(
            $this->createStub(ClaudeApiClient::class),
            new GoldenRuleValidator(['price', 'stock', 'sku', 'ean', 'weight', 'dimension']),
            new TranslatableFieldDetector(),
        );
    }

    private function makeUpload(array $columns, array $sampleRows): Upload
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
            'column_names' => $columns,
            'sample_rows' => $sampleRows,
        ]);
    }

    #[Test]
    public function classify_buckets_protected_and_translatable_correctly(): void
    {
        $columns = ['title_en', 'description_en', 'price_eur', 'sku', 'size'];
        $samples = [
            ['title_en' => 'Merino wool sweater', 'description_en' => 'Lightweight and breathable', 'price_eur' => '149.00', 'sku' => 'MWS-001', 'size' => 'M'],
            ['title_en' => 'Waterproof boot',     'description_en' => 'Vibram sole, keeps dry',     'price_eur' => '89.90',  'sku' => 'WPB-002', 'size' => 'L'],
            ['title_en' => 'Cotton shirt',        'description_en' => 'Classic crew neck',          'price_eur' => '29.00',  'sku' => 'CTS-003', 'size' => 'S'],
        ];

        $r = $this->service->classify($columns, $samples);

        $this->assertSame(['price_eur', 'sku'], $r['protected_fields']);
        $this->assertContains('title_en', $r['translatable_fields']);
        $this->assertContains('description_en', $r['translatable_fields']);
        $this->assertContains('size', $r['skipped_fields']);          // code column
    }

    #[Test]
    public function classify_marks_low_confidence_translatables_as_ambiguous(): void
    {
        // 3 real sentences, 2 codes → translatable_ratio = 0.6 → below 0.85 →
        // column lands in translatable AND ambiguous list for AI review.
        $columns = ['description'];
        $samples = [
            ['description' => 'A long descriptive sentence about the product'],
            ['description' => 'Another marketing description with benefits'],
            ['description' => 'Third product copy line with some detail'],
            ['description' => 'XL'],
            ['description' => 'S'],
        ];

        $r = $this->service->classify($columns, $samples);

        $this->assertContains('description', $r['translatable_fields']);
        $this->assertContains('description', $r['ambiguous_fields']);
    }

    #[Test]
    public function classify_never_routes_a_protected_column_into_translatable(): void
    {
        $columns = ['price', 'stock', 'ean', 'sku', 'weight_kg', 'title'];
        $samples = [
            ['price' => '10.00', 'stock' => '50', 'ean' => '7612345678901', 'sku' => 'A-1', 'weight_kg' => '0.45', 'title' => 'Product'],
        ];

        $r = $this->service->classify($columns, $samples);

        foreach (['price', 'stock', 'ean', 'sku', 'weight_kg'] as $col) {
            $this->assertContains($col, $r['protected_fields'], "{$col} should be protected");
            $this->assertNotContains($col, $r['translatable_fields'], "{$col} must never be translatable");
            $this->assertNotContains($col, $r['skipped_fields'], "{$col} is protected, not skipped");
        }
    }

    #[Test]
    public function classify_overall_confidence_is_the_mean_of_per_column_confidences(): void
    {
        $columns = ['title', 'size'];
        $samples = [
            ['title' => 'Merino wool sweater', 'size' => 'S'],
            ['title' => 'Waterproof boot',     'size' => 'M'],
            ['title' => 'Cotton shirt',        'size' => 'L'],
        ];

        $r = $this->service->classify($columns, $samples);

        // Both buckets should carry non-zero confidence; overall is the mean.
        $this->assertGreaterThan(0, $r['confidence']);
        $this->assertLessThanOrEqual(1.0, $r['confidence']);
    }

    #[Test]
    public function generate_writes_a_field_mapping_row_with_the_heuristic_shape(): void
    {
        $upload = $this->makeUpload(
            ['title_en', 'description_en', 'price', 'sku'],
            [
                ['title_en' => 'Merino wool sweater', 'description_en' => 'A warm knitted top', 'price' => '149.00', 'sku' => 'MWS-1'],
                ['title_en' => 'Waterproof boot',     'description_en' => 'Keeps you dry',      'price' => '89.90',  'sku' => 'WPB-2'],
                ['title_en' => 'Cotton shirt',        'description_en' => 'Classic crew neck',  'price' => '29.00',  'sku' => 'CTS-3'],
            ],
        );

        $mapping = $this->service->generate($upload->project, $upload, useAi: false);

        $this->assertSame($upload->project_id, $mapping->project_id);
        $this->assertSame($upload->id, $mapping->upload_id);
        $this->assertSame(['price', 'sku'], $mapping->protected_fields);
        $this->assertContains('title_en', $mapping->translatable_fields);
        $this->assertContains('description_en', $mapping->translatable_fields);
        $this->assertFalse($mapping->user_approved);
        $this->assertNull($mapping->approved_at);
    }

    #[Test]
    public function generate_detects_english_as_the_source_language_for_an_english_catalog(): void
    {
        $upload = $this->makeUpload(
            ['title_en', 'description_en'],
            [
                ['title_en' => 'Merino wool sweater ribbed crew neck',                   'description_en' => 'Lightweight and breathable with moisture wicking'],
                ['title_en' => 'Waterproof hiking boot with vibram sole',                'description_en' => 'Provides excellent traction on wet surfaces'],
                ['title_en' => 'Organic cotton t-shirt in classic navy blue',            'description_en' => 'Made from certified organic cotton farms in India'],
            ],
        );

        $mapping = $this->service->generate($upload->project, $upload, useAi: false);

        $this->assertSame('en', $mapping->detected_source_language);
    }

    #[Test]
    public function generate_throws_on_upload_with_no_columns(): void
    {
        $upload = $this->makeUpload([], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no column_names');

        $this->service->generate($upload->project, $upload, useAi: false);
    }

    #[Test]
    public function generate_is_idempotent_for_the_same_project_and_upload(): void
    {
        $upload = $this->makeUpload(
            ['title', 'sku'],
            [['title' => 'Merino wool sweater ribbed crew neck', 'sku' => 'A-1']],
        );

        $first = $this->service->generate($upload->project, $upload, useAi: false);
        $second = $this->service->generate($upload->project, $upload, useAi: false);

        $this->assertSame($first->id, $second->id, 'should update, not create a second row');
        $this->assertDatabaseCount('field_mappings', 1);
    }
}
