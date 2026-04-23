<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Translation;

use App\Models\Product;
use App\Models\Project;
use App\Models\Team;
use App\Models\Translation;
use App\Services\Translation\TranslationCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the team-scoped translation cache.
 *
 * Tenant isolation is the single most important property here — a cache hit
 * leaking from team A to team B would expose one merchant's translations to
 * another. These tests lock that boundary in place so a future regression
 * fails loudly instead of silently.
 */
class TranslationCacheTest extends TestCase
{
    use RefreshDatabase;

    private TranslationCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new TranslationCache();
    }

    private function makeTeamWithProduct(string $name = 'Team'): Product
    {
        $team = Team::create(['name' => $name, 'owner_id' => 1, 'slug' => strtolower($name) . '-' . uniqid()]);
        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'Project for ' . $name,
            'status' => 'draft',
        ]);
        return Product::create([
            'project_id' => $project->id,
            'row_number' => 1,
            'source_data' => ['title' => 'seed'],
            'content_hash' => hash('sha256', (string) microtime(true)),
        ]);
    }

    #[Test]
    public function hash_is_a_sha256_of_the_trimmed_source_text(): void
    {
        $this->assertSame(
            hash('sha256', 'Merino wool sweater'),
            TranslationCache::hash('  Merino wool sweater  ')
        );
    }

    #[Test]
    public function hash_collisions_are_case_and_whitespace_sensitive_on_purpose(): void
    {
        // Leading/trailing whitespace is trimmed; casing/punctuation is not.
        $this->assertNotSame(
            TranslationCache::hash('merino wool sweater'),
            TranslationCache::hash('Merino wool sweater')
        );
        $this->assertNotSame(
            TranslationCache::hash('Merino wool sweater'),
            TranslationCache::hash('Merino wool sweater.')
        );
    }

    #[Test]
    public function get_returns_null_on_cache_miss(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        $this->assertNull($this->cache->get('nothing cached', 'de', $teamId));
    }

    #[Test]
    public function get_returns_an_existing_translation_for_the_same_team(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        $this->cache->put(
            productId: $product->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
        );

        $hit = $this->cache->get('Merino wool sweater', 'de', $teamId);

        $this->assertNotNull($hit);
        $this->assertSame('Merinowollpullover', $hit['translated_text']);
        $this->assertSame('claude', $hit['provider']);
    }

    #[Test]
    public function get_does_not_return_a_translation_from_a_different_team(): void
    {
        // Team A creates a translation.
        $productA = $this->makeTeamWithProduct('TeamA');
        $this->cache->put(
            productId: $productA->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
        );

        // Team B asks the cache for the same source + target.
        $productB = $this->makeTeamWithProduct('TeamB');
        $teamBId = $productB->project->team_id;

        $hit = $this->cache->get('Merino wool sweater', 'de', $teamBId);

        // Must be a miss — otherwise translations leak across tenants.
        $this->assertNull($hit);
    }

    #[Test]
    public function get_batch_resolves_every_input_text_to_a_hit_or_null(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        $this->cache->put(
            productId: $product->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
        );
        $this->cache->put(
            productId: $product->id,
            fieldName: 'title',
            sourceText: 'Cotton shirt',
            targetLanguage: 'de',
            translatedText: 'Baumwollhemd',
            provider: 'microsoft',
        );

        $batch = $this->cache->getBatch(
            ['Merino wool sweater', 'Waterproof boot', 'Cotton shirt'],
            'de',
            $teamId,
        );

        $this->assertSame(['Merino wool sweater', 'Waterproof boot', 'Cotton shirt'], array_keys($batch));
        $this->assertNotNull($batch['Merino wool sweater']);
        $this->assertNull($batch['Waterproof boot']);                     // miss stays null
        $this->assertSame('Baumwollhemd', $batch['Cotton shirt']['translated_text']);
    }

    #[Test]
    public function get_batch_does_not_leak_between_teams(): void
    {
        $productA = $this->makeTeamWithProduct('TeamA');
        $this->cache->put(
            productId: $productA->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
        );

        $productB = $this->makeTeamWithProduct('TeamB');
        $teamBId = $productB->project->team_id;

        $batch = $this->cache->getBatch(['Merino wool sweater'], 'de', $teamBId);
        $this->assertNull($batch['Merino wool sweater']);
    }

    #[Test]
    public function get_only_returns_translations_for_the_requested_target_language(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        $this->cache->put(
            productId: $product->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
        );

        $this->assertNotNull($this->cache->get('Merino wool sweater', 'de', $teamId));
        $this->assertNull($this->cache->get('Merino wool sweater', 'fr', $teamId));
    }

    #[Test]
    public function put_persists_the_correct_hash_and_row_shape(): void
    {
        $product = $this->makeTeamWithProduct();

        $translation = $this->cache->put(
            productId: $product->id,
            fieldName: 'title',
            sourceText: 'Merino wool sweater',
            targetLanguage: 'de',
            translatedText: 'Merinowollpullover',
            provider: 'claude',
            isCached: true,
            qualityScore: 0.91,
        );

        $this->assertSame(TranslationCache::hash('Merino wool sweater'), $translation->source_hash);
        $this->assertTrue($translation->is_cached);
        $this->assertSame(0.91, (float) $translation->quality_score);
        $this->assertDatabaseHas('translations', [
            'id' => $translation->id,
            'product_id' => $product->id,
            'provider' => 'claude',
            'target_language' => 'de',
        ]);
    }

    #[Test]
    public function rows_with_null_translated_text_never_serve_as_cache_hits(): void
    {
        // An in-flight or failed translation (translated_text still null) must
        // NOT be served from the cache — it's not a real translation yet.
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        Translation::create([
            'product_id' => $product->id,
            'field_name' => 'title',
            'source_text' => 'Merino wool sweater',
            'source_hash' => TranslationCache::hash('Merino wool sweater'),
            'target_language' => 'de',
            'translated_text' => null,
            'provider' => 'claude',
            'is_cached' => false,
        ]);

        $this->assertNull($this->cache->get('Merino wool sweater', 'de', $teamId));
    }
}
