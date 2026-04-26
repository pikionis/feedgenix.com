<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunTranslation;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\Project;
use App\Models\Team;
use App\Models\Translation;
use App\Models\TranslationJob;
use App\Models\TranslationQuota;
use App\Models\UsageLog;
use App\Services\Translation\Providers\DeepLTranslator;
use App\Services\Translation\Providers\GrokTranslator;
use App\Services\Translation\Providers\MicrosoftTranslator;
use App\Services\Translation\TranslationCache;
use App\Services\Translation\TranslationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Services\Translation\FakeProvider;
use Tests\TestCase;

// Pull in the FakeProvider definition used by the router tests.
require_once __DIR__ . '/../Services/Translation/TranslationRouterTest.php';

/**
 * RunTranslation is the gnarliest job — long-running, resumable, touches
 * every translation row. These tests pin down the non-negotiable properties:
 *   - Missing preconditions (no mapping / no langs) → job fails, no retry.
 *   - Monthly cap or "all exhausted" mid-run → job marks failed, doesn't retry.
 *   - Resumability: existing translations are skipped on re-run.
 *   - Team monthly usage is incremented only on completion.
 */
class RunTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('feedgenix.translation.monthly_cap_eur', 10.00);
        Cache::flush();
        FakeProvider::$callCount = [];
    }

    /**
     * Build a default-happy fixture.
     *
     * $opts keys:
     *   targets:       string[]  — defaults ['de', 'fr']
     *   translatable:  string[]  — defaults ['title', 'description']
     *   source_lang:   string    — defaults 'en'
     *   approved:      bool      — defaults true
     *   products:      int       — defaults 2
     */
    private function seedFixture(array $opts = []): array
    {
        $opts = array_merge([
            'targets' => ['de', 'fr'],
            'translatable' => ['title', 'description'],
            'source_lang' => 'en',
            'approved' => true,
            'products' => 2,
        ], $opts);

        $team = Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'products_used_this_month' => 0,
        ]);

        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'P',
            'status' => 'review',
            'source_language' => $opts['source_lang'],
            'target_languages' => $opts['targets'],
        ]);

        FieldMapping::create([
            'project_id' => $project->id,
            'upload_id' => null,
            'mapping' => [],
            'translatable_fields' => $opts['translatable'],
            'protected_fields' => ['price', 'sku'],
            'skipped_fields' => [],
            'ambiguous_fields' => [],
            'detected_source_language' => $opts['source_lang'],
            'user_approved' => $opts['approved'],
            'approved_at' => $opts['approved'] ? now() : null,
        ]);

        $products = [];
        for ($i = 1; $i <= $opts['products']; $i++) {
            $products[] = Product::create([
                'project_id' => $project->id,
                'external_id' => "SKU-{$i}",
                'row_number' => $i,
                'source_data' => [
                    'title' => "Product title {$i}",
                    'description' => "Long description {$i} with enough text",
                    'price' => '10.00',
                    'sku' => "SKU-{$i}",
                ],
                'content_hash' => hash('sha256', (string) $i),
            ]);
        }

        $job = TranslationJob::create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'status' => 'queued',
        ]);

        return compact('team', 'project', 'job', 'products');
    }

    /**
     * Build a router that answers every provider call with a fake that echoes
     * its input prefixed with the target language. No HTTP, no quota tracking
     * for real providers — just deterministic output.
     */
    private function fakeRouter(): TranslationRouter
    {
        $fake = FakeProvider::echoing('microsoft');

        $r = new TranslationRouter(
            new TranslationCache(),
            app(MicrosoftTranslator::class),
            app(DeepLTranslator::class),
            app(GrokTranslator::class),
        );

        $ref = new \ReflectionClass($r);
        $prop = $ref->getProperty('providers');
        $prop->setAccessible(true);
        $prop->setValue($r, [$fake, FakeProvider::unconfigured('deepl'), FakeProvider::unconfigured('grok')]);

        return $r;
    }

    #[Test]
    public function job_fails_when_no_approved_mapping_exists(): void
    {
        $ctx = $this->seedFixture(['approved' => false]);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('failed', $ctx['job']->status);
        $this->assertStringContainsString('approved field mapping', $ctx['job']->error_message);
        $this->assertSame(0, Translation::count());
    }

    #[Test]
    public function job_fails_when_target_languages_is_empty(): void
    {
        $ctx = $this->seedFixture(['targets' => []]);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('failed', $ctx['job']->status);
        $this->assertStringContainsString('Missing', $ctx['job']->error_message);
    }

    #[Test]
    public function job_fails_when_translatable_fields_is_empty(): void
    {
        $ctx = $this->seedFixture(['translatable' => []]);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('failed', $ctx['job']->status);
        $this->assertStringContainsString('Missing', $ctx['job']->error_message);
    }

    #[Test]
    public function happy_path_writes_one_translation_per_cell_and_marks_completed(): void
    {
        $ctx = $this->seedFixture();

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('completed', $ctx['job']->status);
        $this->assertNotNull($ctx['job']->completed_at);
        $this->assertSame(2, $ctx['job']->processed_products);

        // 2 products × 2 fields × 2 languages = 8 translation rows.
        $this->assertSame(8, Translation::count());
        $this->assertSame(0, Translation::whereNull('translated_text')->count());
    }

    #[Test]
    public function happy_path_increments_team_monthly_usage_by_processed_products(): void
    {
        $ctx = $this->seedFixture();

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['team']->refresh();
        $this->assertSame(2, $ctx['team']->products_used_this_month);
    }

    #[Test]
    public function happy_path_writes_a_usage_log_for_billing(): void
    {
        $ctx = $this->seedFixture();

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $log = UsageLog::where('team_id', $ctx['team']->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('translation', $log->action);
        $this->assertSame(2, $log->quantity);
        $this->assertSame($ctx['project']->id, $log->metadata['project_id']);
        // The log should differentiate processed vs billable so finance can
        // see how much cache helped — even when they happen to match.
        $this->assertSame(2, $log->metadata['products_processed']);
        $this->assertSame(2, $log->metadata['products_billable']);
    }

    #[Test]
    public function products_served_entirely_from_cache_do_not_count_against_quota(): void
    {
        // Seed: 2 products × 2 fields × 2 langs = 8 cells. Pre-fill the cache
        // so EVERY router lookup is a hit — no AI calls happen.
        $ctx = $this->seedFixture();
        $cache = new TranslationCache();

        // Seed cache via a separate "cache donor" project under the same team
        // so hits resolve via the team-scoped query in TranslationCache::getBatch.
        // Put the donor in a SEPARATE project (same team) so it doesn't get
        // included in the product set the job iterates over.
        $donorProject = \App\Models\Project::create([
            'team_id' => $ctx['team']->id,
            'name' => 'Donor',
            'status' => 'completed',
        ]);
        $donor = Product::create([
            'project_id' => $donorProject->id,
            'external_id' => 'donor',
            'row_number' => 1,
            'source_data' => [],
            'content_hash' => hash('sha256', 'donor-' . uniqid()),
        ]);
        foreach ($ctx['products'] as $p) {
            foreach (['title', 'description'] as $field) {
                foreach (['de', 'fr'] as $lang) {
                    $cache->put(
                        productId: $donor->id,
                        fieldName: $field,
                        sourceText: $p->source_data[$field],
                        targetLanguage: $lang,
                        translatedText: "[{$lang}] {$p->source_data[$field]}",
                        provider: 'seed',
                    );
                }
            }
        }

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), $cache);

        $ctx['job']->refresh();
        $this->assertSame('completed', $ctx['job']->status);

        // Team usage MUST stay at zero — no AI was called for any product.
        $ctx['team']->refresh();
        $this->assertSame(0, $ctx['team']->products_used_this_month);

        // The usage log should clearly separate processed (2) from billable (0).
        $log = UsageLog::where('team_id', $ctx['team']->id)->first();
        $this->assertSame(0, $log->quantity);
        $this->assertSame(2, $log->metadata['products_processed']);
        $this->assertSame(0, $log->metadata['products_billable']);
        $this->assertGreaterThan(0, $log->metadata['cached']);
    }

    #[Test]
    public function only_products_with_at_least_one_ai_translation_are_billable(): void
    {
        // 2 products × 2 fields × 2 langs = 8 cells. Pre-fill the cache for
        // product[0] only — so product[1] still needs AI calls. After the run,
        // exactly 1 billable product, not 2.
        $ctx = $this->seedFixture();
        $cache = new TranslationCache();

        $donorProject = \App\Models\Project::create([
            'team_id' => $ctx['team']->id,
            'name' => 'Donor mixed',
            'status' => 'completed',
        ]);
        $donor = Product::create([
            'project_id' => $donorProject->id,
            'external_id' => 'donor-mixed',
            'row_number' => 1,
            'source_data' => [],
            'content_hash' => hash('sha256', 'donor-mixed-' . uniqid()),
        ]);
        $p0 = $ctx['products'][0];
        foreach (['title', 'description'] as $field) {
            foreach (['de', 'fr'] as $lang) {
                $cache->put(
                    productId: $donor->id,
                    fieldName: $field,
                    sourceText: $p0->source_data[$field],
                    targetLanguage: $lang,
                    translatedText: "[{$lang}] {$p0->source_data[$field]}",
                    provider: 'seed',
                );
            }
        }

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), $cache);

        $ctx['team']->refresh();
        $this->assertSame(1, $ctx['team']->products_used_this_month, 'Only product[1] needed AI');

        $log = UsageLog::where('team_id', $ctx['team']->id)->first();
        $this->assertSame(1, $log->quantity);
        $this->assertSame(1, $log->metadata['products_billable']);
        $this->assertSame(2, $log->metadata['products_processed']);
    }

    #[Test]
    public function re_running_a_completed_job_is_a_no_op(): void
    {
        $ctx = $this->seedFixture();
        $ctx['job']->update(['status' => 'completed']);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        // Nothing created — translation table stays empty.
        $this->assertSame(0, Translation::count());
        $ctx['job']->refresh();
        $this->assertSame('completed', $ctx['job']->status);
    }

    #[Test]
    public function cancelled_job_is_not_processed(): void
    {
        $ctx = $this->seedFixture();
        $ctx['job']->update(['status' => 'cancelled']);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $this->assertSame(0, Translation::count());
        $ctx['job']->refresh();
        $this->assertSame('cancelled', $ctx['job']->status);
    }

    #[Test]
    public function resumability_skips_rows_that_already_have_translations(): void
    {
        $ctx = $this->seedFixture();

        // Pre-create one translation for (product1, title, de). Re-running
        // the job should leave that row untouched and still fill the 7 others.
        $p1 = $ctx['products'][0];
        Translation::create([
            'product_id' => $p1->id,
            'field_name' => 'title',
            'source_text' => $p1->source_data['title'],
            'source_hash' => TranslationCache::hash($p1->source_data['title']),
            'target_language' => 'de',
            'translated_text' => 'ALREADY TRANSLATED',
            'provider' => 'manual',
            'is_cached' => false,
        ]);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('completed', $ctx['job']->status);

        // 7 new rows created + 1 pre-existing = 8 total.
        $this->assertSame(8, Translation::count());

        // The pre-existing row's text must be unchanged — resumability means
        // "skip", not "overwrite".
        $preserved = Translation::where('product_id', $p1->id)
            ->where('field_name', 'title')
            ->where('target_language', 'de')
            ->first();
        $this->assertSame('ALREADY TRANSLATED', $preserved->translated_text);
    }

    #[Test]
    public function monthly_cap_reached_marks_job_failed_without_retry(): void
    {
        $ctx = $this->seedFixture();

        // Exhaust the monthly cap so the router throws before the first call.
        TranslationQuota::create([
            'provider' => 'grok',
            'year' => now()->year,
            'month' => now()->month,
            'chars_used' => 10_000_000,
            'chars_limit' => 0,
            'cost_eur' => 10.00,
        ]);

        (new RunTranslation($ctx['job']->id))->handle($this->fakeRouter(), new TranslationCache());

        $ctx['job']->refresh();
        $this->assertSame('failed', $ctx['job']->status);
        $this->assertStringContainsString('cap', mb_strtolower($ctx['job']->error_message));

        // Team usage must NOT be incremented — the work didn't finish.
        $ctx['team']->refresh();
        $this->assertSame(0, $ctx['team']->products_used_this_month);
    }
}
