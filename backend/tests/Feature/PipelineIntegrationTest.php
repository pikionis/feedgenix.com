<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\Translation;
use App\Models\TranslationJob;
use App\Models\User;
use App\Services\Translation\Providers\DeepLTranslator;
use App\Services\Translation\Providers\GrokTranslator;
use App\Services\Translation\Providers\MicrosoftTranslator;
use App\Services\Translation\TranslationCache;
use App\Services\Translation\TranslationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\FileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Services\Translation\FakeProvider;
use Tests\TestCase;

// Pull in the FakeProvider shared with the router tests.
require_once __DIR__ . '/Services/Translation/TranslationRouterTest.php';

/**
 * End-to-end pipeline: create project → upload CSV → AI mapping (heuristics
 * only, no Claude) → approve mapping → translate (fake provider) → download
 * export. The test runs against the real queue (sync connection from
 * phpunit.xml) so ProcessUpload, RunFieldMapping, and RunTranslation all
 * execute inline when dispatched.
 *
 * Covers the happy path + the one failure that only surfaces at this scope:
 * a translation attempt made without an approved mapping must 422 from the
 * controller (not silently run).
 */
class PipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Swap the real TranslationRouter for one that echoes every input via
        // a fake provider. Runs for the whole test lifetime.
        $this->app->bind(TranslationRouter::class, function () {
            $router = new TranslationRouter(
                new TranslationCache(),
                app(MicrosoftTranslator::class),
                app(DeepLTranslator::class),
                app(GrokTranslator::class),
            );
            $fake = FakeProvider::echoing('microsoft');
            $ref = new \ReflectionClass($router);
            $prop = $ref->getProperty('providers');
            $prop->setAccessible(true);
            $prop->setValue($router, [$fake, FakeProvider::unconfigured('deepl'), FakeProvider::unconfigured('grok')]);
            return $router;
        });
        FakeProvider::$callCount = [];
    }

    private function actingAsTeamMember(string $plan = 'starter'): User
    {
        $team = Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'products_used_this_month' => 0,
        ]);

        // Paid tiers must have an active subscription record for CheckSubscription
        // to let them through. Attach one locally — no Stripe call needed.
        if ($plan !== 'free') {
            \Laravel\Cashier\Subscription::create([
                'team_id' => $team->id,
                'type' => 'default',
                'stripe_id' => 'sub_test_' . uniqid(),
                'stripe_status' => 'active',
                'stripe_price' => 'price_test',
                'quantity' => 1,
            ]);
        }

        $user = User::create([
            'name' => 'Operator',
            'email' => 'op-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'team_id' => $team->id,
            'role' => 'owner',
        ]);

        Sanctum::actingAs($user);
        return $user;
    }

    private function csvFile(): UploadedFile
    {
        $csv = "sku,title,description,price\n"
            . "SKU-1,Merino wool sweater ribbed crew neck,Lightweight breathable and moisture wicking,149.00\n"
            . "SKU-2,Waterproof hiking boot vibram sole,Provides excellent traction wet surfaces,89.90\n"
            . "SKU-3,Organic cotton t-shirt classic blue,Made from certified organic cotton farms,29.00\n";

        return (new FileFactory())->createWithContent('products.csv', $csv);
    }

    #[Test]
    public function full_pipeline_from_upload_to_export_happy_path(): void
    {
        $user = $this->actingAsTeamMember();

        // 1) Create a project with target languages.
        $project = $this->postJson('/api/projects', [
            'name' => 'Winter catalog',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr'],
        ])->assertCreated()
          ->assertJsonPath('status', 'draft')
          ->json();

        // 2) Upload the CSV. This runs ProcessUpload → RunFieldMapping
        //    synchronously via the sync queue.
        $this->postJson(
            "/api/projects/{$project['id']}/upload",
            ['file' => $this->csvFile()],
        )->assertCreated();

        // 3) Inspect the resulting mapping — heuristics alone are enough with
        //    useAi defaulting to true, but Claude isn't configured in tests so
        //    FieldMappingService falls back to heuristics. Either way: the
        //    row should exist, and price/sku must be in protected_fields.
        $mapping = $this->getJson("/api/projects/{$project['id']}/mapping")
            ->assertOk()
            ->json();

        $this->assertContains('price', $mapping['protected_fields']);
        $this->assertContains('sku',   $mapping['protected_fields']);
        $this->assertContains('title', $mapping['translatable_fields']);
        $this->assertContains('description', $mapping['translatable_fields']);

        // 4) Approve the mapping.
        $this->putJson("/api/projects/{$project['id']}/mapping", [
            'user_approved' => true,
        ])->assertOk()
          ->assertJsonPath('user_approved', true);

        // 5) Kick off translation.
        $this->postJson("/api/projects/{$project['id']}/translate")
            ->assertCreated();

        // RunTranslation dispatched synchronously — assert the terminal state.
        $job = TranslationJob::where('project_id', $project['id'])->latest()->first();
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status, 'Job should be completed after sync dispatch');
        $this->assertSame(3, $job->processed_products);

        // 3 products × 2 fields × 2 langs = 12 translation rows, all filled.
        $this->assertSame(12, Translation::count());
        $this->assertSame(0, Translation::whereNull('translated_text')->count());

        // 6) Poll status for parity with a real client.
        $this->getJson("/api/projects/{$project['id']}/status")
            ->assertOk()
            ->assertJsonPath('job.status', 'completed');

        // 7) Download the CSV export.
        $response = $this->get("/api/projects/{$project['id']}/export");
        $response->assertOk();
        $body = $response->streamedContent();

        // Header carries the original columns + every (field × target_lang) combo.
        $firstLine = strtok($body, "\n");
        foreach (['sku', 'title', 'description', 'price', 'title_de', 'description_de', 'title_fr', 'description_fr'] as $col) {
            $this->assertStringContainsString($col, $firstLine, "Export header missing '{$col}'");
        }

        // Protected columns pass through untouched.
        $this->assertStringContainsString('149.00', $body);
        $this->assertStringContainsString('SKU-1',  $body);

        // Team usage incremented by processed products.
        $user->team->refresh();
        $this->assertSame(3, $user->team->products_used_this_month);
    }

    #[Test]
    public function translate_endpoint_rejects_a_project_without_an_approved_mapping(): void
    {
        $this->actingAsTeamMember();

        $project = $this->postJson('/api/projects', [
            'name' => 'Spring catalog',
            'source_language' => 'en',
            'target_languages' => ['de'],
        ])->assertCreated()->json();

        $this->postJson("/api/projects/{$project['id']}/upload", [
            'file' => $this->csvFile(),
        ])->assertCreated();

        // Skip approving the mapping — try to translate straight away.
        $this->postJson("/api/projects/{$project['id']}/translate")
            ->assertStatus(422)
            ->assertJson(['message' => 'Field mapping must be approved before starting translation.']);

        $this->assertSame(0, Translation::count());
        $this->assertSame(0, TranslationJob::count());
    }

    #[Test]
    public function translate_endpoint_rejects_a_project_that_has_no_products(): void
    {
        $this->actingAsTeamMember();

        $project = $this->postJson('/api/projects', [
            'name' => 'Empty',
            'source_language' => 'en',
            'target_languages' => ['de'],
        ])->assertCreated()->json();

        // Don't upload anything, but simulate mapping approval by writing
        // the mapping row directly. The products table stays empty → 422.
        \App\Models\FieldMapping::create([
            'project_id' => $project['id'],
            'upload_id' => null,
            'mapping' => [],
            'translatable_fields' => ['title'],
            'protected_fields' => [],
            'skipped_fields' => [],
            'ambiguous_fields' => [],
            'detected_source_language' => 'en',
            'user_approved' => true,
            'approved_at' => now(),
        ]);

        $this->postJson("/api/projects/{$project['id']}/translate")
            ->assertStatus(422)
            ->assertJson(['message' => 'No products found. Upload a file first.']);
    }
}
