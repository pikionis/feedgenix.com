<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Translation;

use App\Models\Product;
use App\Models\Project;
use App\Models\Team;
use App\Models\TranslationQuota;
use App\Services\Translation\Providers\DeepLTranslator;
use App\Services\Translation\Providers\GrokTranslator;
use App\Services\Translation\Providers\MicrosoftTranslator;
use App\Services\Translation\Providers\TranslationProviderInterface;
use App\Services\Translation\TranslationCache;
use App\Services\Translation\TranslationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests exercise the router via fake providers so we never make real HTTP
 * requests. The focus is on the money-critical rules:
 *   - Cache hits short-circuit before any provider is called.
 *   - Free providers are preferred over paid.
 *   - Global and per-provider EUR caps stop paid calls before they happen.
 *   - The circuit breaker parks a misbehaving provider for 10 min.
 *   - All-exhausted + cache-miss throws a RuntimeException (not silent 0s).
 */
class TranslationRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('feedgenix.translation.monthly_cap_eur', 10.00);
        Cache::flush();
        // Static call counter is shared — reset between tests so assertions stay isolated.
        FakeProvider::$callCount = [];
    }

    private function makeTeamWithProduct(): Product
    {
        $team = Team::create(['name' => 'Acme', 'owner_id' => 1, 'slug' => 'acme-' . uniqid()]);
        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'P1',
            'status' => 'draft',
        ]);
        return Product::create([
            'project_id' => $project->id,
            'row_number' => 1,
            'source_data' => [],
            'content_hash' => hash('sha256', (string) microtime(true)),
        ]);
    }

    /**
     * Build a router with the given provider order. Any provider not passed
     * becomes a "not configured" stub so only the providers we care about
     * participate.
     */
    private function router(array $providers, ?TranslationCache $cache = null): TranslationRouter
    {
        $cache ??= new TranslationCache();

        $microsoft = $providers[0] ?? FakeProvider::unconfigured('microsoft');
        $deepl     = $providers[1] ?? FakeProvider::unconfigured('deepl');
        $grok      = $providers[2] ?? FakeProvider::unconfigured('grok');

        // The router types its constructor against concrete provider classes;
        // we need real instances of those classes to pass PHP's type check.
        // We stash the fakes as an injected array via reflection.
        $r = new TranslationRouter(
            $cache,
            app(MicrosoftTranslator::class),
            app(DeepLTranslator::class),
            app(GrokTranslator::class),
        );

        $ref = new \ReflectionClass($r);
        $prop = $ref->getProperty('providers');
        $prop->setAccessible(true);
        $prop->setValue($r, [$microsoft, $deepl, $grok]);

        return $r;
    }

    #[Test]
    public function cache_hit_skips_every_provider_and_returns_zero_cost(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        $cache = new TranslationCache();
        $cache->put($product->id, 'title', 'Merino wool sweater', 'de', 'Merinowollpullover', 'claude');

        // Use a provider that would throw if it were ever called — to prove
        // the cache hit short-circuits ahead of provider selection.
        $free = FakeProvider::throwing('microsoft');
        $router = $this->router([$free], $cache);

        $out = $router->translateBatch(['Merino wool sweater'], 'en', 'de', $teamId);

        $this->assertSame('cache', $out['results'][0]['provider']);
        $this->assertSame(1, $out['stats']['cached']);
        $this->assertSame(0, $out['stats']['fresh']);
        $this->assertSame(0.0, $out['stats']['cost_eur']);
        $this->assertSame(0, FakeProvider::$callCount['microsoft'] ?? 0);
    }

    #[Test]
    public function first_configured_free_provider_is_selected_over_paid(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        $free = FakeProvider::ok('microsoft', 2_000_000, 0.0);
        $paid = FakeProvider::ok('grok', 0, 0.50);

        $out = $this->router([$free, null, $paid])->translateBatch(
            ['Hello world'], 'en', 'de', $teamId,
        );

        $this->assertSame('microsoft', $out['results'][0]['provider']);
        $this->assertSame(0.0, $out['stats']['cost_eur']);
        $this->assertSame(1, FakeProvider::$callCount['microsoft']);
        $this->assertArrayNotHasKey('grok', FakeProvider::$callCount);
    }

    #[Test]
    public function free_provider_exhausted_quota_falls_through_to_next_provider(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        TranslationQuota::create([
            'provider' => 'microsoft',
            'year' => now()->year,
            'month' => now()->month,
            'chars_used' => 2_000_000,   // fully spent for this month
            'chars_limit' => 2_000_000,
            'cost_eur' => 0,
        ]);

        $ms   = FakeProvider::ok('microsoft', 2_000_000, 0.0);
        $grok = FakeProvider::ok('grok', 0, 0.50);

        $out = $this->router([$ms, null, $grok])->translateBatch(
            ['Hello'], 'en', 'de', $teamId,
        );

        $this->assertSame('grok', $out['results'][0]['provider']);
        $this->assertArrayNotHasKey('microsoft', FakeProvider::$callCount);
    }

    #[Test]
    public function monthly_cap_blocks_every_paid_provider_before_any_call(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        // Burn €10 of grok so we're right at the cap BEFORE this call.
        TranslationQuota::create([
            'provider' => 'grok',
            'year' => now()->year,
            'month' => now()->month,
            'chars_used' => 10_000_000,
            'chars_limit' => 0,
            'cost_eur' => 10.00,
        ]);

        $grok = FakeProvider::ok('grok', 0, 0.50);
        $router = $this->router([null, null, $grok]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('monthly cap');

        $router->translateBatch(['Hello world'], 'en', 'de', $teamId);
    }

    #[Test]
    public function per_provider_paid_cap_takes_that_provider_out_of_rotation(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        // Grok's per-provider cap is €100. Raise the global EUR cap well
        // above that so the per-provider check is what actually fires here.
        config()->set('feedgenix.translation.monthly_cap_eur', 500.00);
        TranslationQuota::create([
            'provider' => 'grok',
            'year' => now()->year,
            'month' => now()->month,
            'chars_used' => 200_000_000,
            'chars_limit' => 0,
            'cost_eur' => 100.00, // hits the per-provider grok cap
        ]);

        $grok = FakeProvider::ok('grok', 0, 0.50);
        $router = $this->router([null, null, $grok]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('providers exhausted');

        $router->translateBatch(['Hello world'], 'en', 'de', $teamId);
    }

    #[Test]
    public function quota_is_incremented_after_a_successful_free_call(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        $ms = FakeProvider::ok('microsoft', 2_000_000, 0.0);
        $this->router([$ms])->translateBatch(['Hello world'], 'en', 'de', $teamId);

        $quota = TranslationQuota::where('provider', 'microsoft')->first();
        $this->assertSame(mb_strlen('Hello world'), $quota->chars_used);
        $this->assertSame(0.0, (float) $quota->cost_eur);
    }

    #[Test]
    public function circuit_breaker_parks_a_provider_after_five_consecutive_failures(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        $flaky = FakeProvider::throwing('microsoft');
        $grok  = FakeProvider::ok('grok', 0, 0.50);

        $router = $this->router([$flaky, null, $grok]);

        // Five failing attempts — each attempt throws and is recorded.
        for ($i = 0; $i < 5; $i++) {
            try {
                $router->translateBatch(['t' . $i], 'en', 'de', $teamId);
            } catch (\Throwable) {
                // expected — flaky provider throws
            }
        }

        // On the next call, microsoft should be circuit-broken and skipped
        // entirely — the router routes straight to grok instead.
        $out = $router->translateBatch(['Hello'], 'en', 'de', $teamId);
        $this->assertSame('grok', $out['results'][0]['provider']);
    }

    #[Test]
    public function all_exhausted_and_cache_miss_throws_a_runtime_exception(): void
    {
        $teamId = $this->makeTeamWithProduct()->project->team_id;

        // Nothing configured.
        $router = $this->router([null, null, null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('providers exhausted');

        $router->translateBatch(['Hello'], 'en', 'de', $teamId);
    }

    #[Test]
    public function results_preserve_input_ordering_across_cache_hits_and_fresh_calls(): void
    {
        $product = $this->makeTeamWithProduct();
        $teamId = $product->project->team_id;

        // Pre-cache the middle text.
        (new TranslationCache())->put($product->id, 'title', 'SECOND', 'de', 'ZWEITER', 'claude');

        $ms = FakeProvider::echoing('microsoft');    // returns input verbatim
        $out = $this->router([$ms])->translateBatch(
            ['FIRST', 'SECOND', 'THIRD'], 'en', 'de', $teamId,
        );

        $this->assertSame('FIRST',   $out['results'][0]['text']);
        $this->assertSame('ZWEITER', $out['results'][1]['text']);   // from cache
        $this->assertSame('THIRD',   $out['results'][2]['text']);
        $this->assertFalse($out['results'][0]['cached']);
        $this->assertTrue($out['results'][1]['cached']);
        $this->assertFalse($out['results'][2]['cached']);
    }
}

/**
 * Minimal test double used by the router tests. Records how many times it
 * was called, and how (via the static $callCount map for easy assertions).
 */
class FakeProvider implements TranslationProviderInterface
{
    public static array $callCount = [];

    public function __construct(
        private string $providerName,
        private bool $configured,
        private int $freeLimit,
        private float $costPerMillion,
        /** @var string 'ok' | 'throw' | 'echo' */
        private string $mode = 'ok',
    ) {}

    public static function ok(string $name, int $freeLimit, float $costPerMillion): self
    {
        return new self($name, true, $freeLimit, $costPerMillion, 'ok');
    }

    public static function throwing(string $name): self
    {
        return new self($name, true, 2_000_000, 0.0, 'throw');
    }

    public static function echoing(string $name): self
    {
        return new self($name, true, 2_000_000, 0.0, 'echo');
    }

    public static function unconfigured(string $name): self
    {
        return new self($name, false, 0, 0.0, 'ok');
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function label(): string
    {
        return ucfirst($this->providerName);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function freeMonthlyLimit(): int
    {
        return $this->freeLimit;
    }

    public function costPerMillionChars(): float
    {
        return $this->costPerMillion;
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $fieldType = 'default'): array
    {
        self::$callCount[$this->providerName] = (self::$callCount[$this->providerName] ?? 0) + 1;

        if ($this->mode === 'throw') {
            throw new RuntimeException("Forced failure in {$this->providerName}");
        }
        if ($this->mode === 'echo') {
            return $texts;
        }
        // default: return strings prefixed so tests can see we used this provider.
        return array_map(fn ($t) => "[{$this->providerName}] " . $t, $texts);
    }
}
