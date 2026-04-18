<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\TranslationQuota;
use App\Services\Translation\Providers\DeepLTranslator;
use App\Services\Translation\Providers\GrokTranslator;
use App\Services\Translation\Providers\MicrosoftTranslator;
use App\Services\Translation\Providers\TranslationProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orchestrates a batch translation across providers.
 *
 * Pipeline for each input string:
 *   1. Team-scoped cache lookup (TranslationCache) — free, instant.
 *   2. First healthy free provider with remaining quota (Microsoft → DeepL free → …).
 *   3. First paid provider with remaining per-provider cost cap (DeepL Pro → Grok).
 *   4. Otherwise: throw, caller marks the job failed.
 *
 * Circuit breaker: after 5 consecutive failures a provider is cooled down for 10 min.
 * Monthly cap: a global EUR spend cap (config('feedgenix.translation.monthly_cap_eur'))
 * short-circuits the whole router before any paid call.
 */
class TranslationRouter
{
    private const PROVIDER_CAPS_EUR = [
        'deepl' => 5.00,
        'grok'  => 100.00,
    ];

    /** @var TranslationProviderInterface[] ordered: free first, paid last */
    private array $providers;

    public function __construct(
        private readonly TranslationCache $cache,
        MicrosoftTranslator $microsoft,
        DeepLTranslator $deepl,
        GrokTranslator $grok,
    ) {
        // Order matters: free providers first, paid last.
        $this->providers = [$microsoft, $deepl, $grok];
    }

    /**
     * Translate a batch of strings. Returns one result per input index + aggregate stats.
     *
     * @param  string[] $texts
     * @return array{results: array<int, array{text: string, provider: string, cached: bool}>, stats: array<string, mixed>}
     */
    public function translateBatch(
        array $texts,
        string $sourceLang,
        string $targetLang,
        int $teamId,
        string $fieldType = 'default',
    ): array {
        $this->enforceMonthlyCapOrFail();

        $results = [];
        $stats = [
            'total' => count($texts),
            'cached' => 0,
            'fresh' => 0,
            'failed' => 0,
            'chars' => 0,
            'cost_eur' => 0.0,
            'provider_breakdown' => [],
        ];

        // Step 1: cache lookup (batched).
        $cacheHits = $this->cache->getBatch($texts, $targetLang, $teamId);

        $pendingIndexes = [];
        $pendingTexts = [];
        foreach ($texts as $i => $text) {
            $hit = $cacheHits[$text] ?? null;
            if ($hit) {
                $results[$i] = ['text' => $hit['translated_text'], 'provider' => 'cache', 'cached' => true];
                $stats['cached']++;
                $this->bumpProviderStat($stats, 'cache');
            } else {
                $pendingIndexes[] = $i;
                $pendingTexts[] = $text;
            }
        }

        // Step 2: route pending texts through providers.
        if ($pendingTexts !== []) {
            $chars = array_sum(array_map('mb_strlen', $pendingTexts));
            $stats['chars'] = $chars;

            $provider = $this->pickProvider($chars);
            if ($provider === null) {
                $stats['failed'] = count($pendingTexts);
                throw new RuntimeException(sprintf(
                    'All translation providers exhausted or monthly cap (€%.2f) reached. %d texts unprocessed.',
                    (float) config('feedgenix.translation.monthly_cap_eur', 10.00),
                    count($pendingTexts),
                ));
            }

            try {
                $translated = $provider->translateBatch($pendingTexts, $sourceLang, $targetLang, $fieldType);
                $cost = ($chars / 1_000_000) * $provider->costPerMillionChars();
                $this->consumeQuota($provider->name(), $chars, $cost);
                $this->recordResult($provider->name(), true);

                foreach ($pendingIndexes as $j => $originalIndex) {
                    $results[$originalIndex] = [
                        'text' => $translated[$j] ?? '',
                        'provider' => $provider->name(),
                        'cached' => false,
                    ];
                }

                $stats['fresh'] = count($pendingTexts);
                $stats['cost_eur'] = round($cost, 4);
                $this->bumpProviderStat($stats, $provider->name(), count($pendingTexts));
                Log::info("Translation via {$provider->name()}: {$chars} chars, €" . number_format($cost, 4));
            } catch (\Throwable $e) {
                $this->recordResult($provider->name(), false);
                Log::warning("Provider {$provider->name()} failed: " . mb_substr($e->getMessage(), 0, 200));
                throw $e;
            }
        }

        ksort($results);
        return ['results' => $results, 'stats' => $stats];
    }

    /** @return array<string, array<string, mixed>> */
    public function usageStats(): array
    {
        $stats = [];
        $year = now()->year;
        $month = now()->month;

        foreach ($this->providers as $provider) {
            $name = $provider->name();
            $quota = TranslationQuota::where('provider', $name)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            $free = $provider->freeMonthlyLimit();
            $cap = self::PROVIDER_CAPS_EUR[$name] ?? null;
            $charsUsed = (int) ($quota->chars_used ?? 0);
            $costEur = (float) ($quota->cost_eur ?? 0);

            $stats[$name] = [
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
                'chars_used' => $charsUsed,
                'chars_limit' => $free,
                'cost_eur' => $costEur,
                'cost_per_million' => $provider->costPerMillionChars(),
                'provider_cap_eur' => $cap,
                'percentage' => $free > 0 ? min(100, round(($charsUsed / $free) * 100, 1)) : 0,
                'healthy' => $this->isProviderHealthy($name),
                'exhausted' => $this->isExhausted($provider, $charsUsed, $costEur),
            ];
        }

        $stats['monthly_cap_eur'] = (float) config('feedgenix.translation.monthly_cap_eur', 10.00);
        $stats['total_cost_eur'] = array_sum(array_map(
            fn ($s) => $s['cost_eur'] ?? 0,
            array_filter($stats, fn ($v) => is_array($v) && isset($v['cost_eur'])),
        ));
        $stats['cap_percentage'] = $stats['monthly_cap_eur'] > 0
            ? min(100, round(($stats['total_cost_eur'] / $stats['monthly_cap_eur']) * 100, 1))
            : 0;

        return $stats;
    }

    // ─── Provider selection ────────────────────────────────────────────────

    private function pickProvider(int $chars): ?TranslationProviderInterface
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isConfigured()) {
                continue;
            }
            if (!$this->isProviderHealthy($provider->name())) {
                continue;
            }
            if (!$this->hasCapacity($provider, $chars)) {
                continue;
            }
            return $provider;
        }
        return null;
    }

    private function hasCapacity(TranslationProviderInterface $provider, int $chars): bool
    {
        $year = now()->year;
        $month = now()->month;
        $quota = TranslationQuota::where('provider', $provider->name())
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $charsUsed = (int) ($quota->chars_used ?? 0);
        $costUsed = (float) ($quota->cost_eur ?? 0);

        // Free providers: check free-tier limit.
        if ($provider->costPerMillionChars() === 0.0) {
            $limit = $provider->freeMonthlyLimit();
            return $limit === 0 ? false : ($charsUsed + $chars) <= $limit;
        }

        // Paid providers: check per-provider EUR cap and global EUR cap.
        $additionalCost = ($chars / 1_000_000) * $provider->costPerMillionChars();
        $providerCap = self::PROVIDER_CAPS_EUR[$provider->name()] ?? PHP_FLOAT_MAX;
        if (($costUsed + $additionalCost) > $providerCap) {
            return false;
        }
        return !$this->wouldExceedMonthlyCap($additionalCost);
    }

    // ─── Quota bookkeeping ─────────────────────────────────────────────────

    private function consumeQuota(string $provider, int $chars, float $costEur): void
    {
        $quota = TranslationQuota::forPeriod($provider, now()->year, now()->month);
        $quota->increment('chars_used', $chars);
        if ($costEur > 0) {
            $quota->increment('cost_eur', $costEur);
        }
    }

    private function enforceMonthlyCapOrFail(): void
    {
        $cap = (float) config('feedgenix.translation.monthly_cap_eur', 10.00);
        $spent = $this->monthlySpend();
        if ($spent >= $cap) {
            throw new RuntimeException(sprintf(
                'TRANSLATION BLOCKED: monthly cap of €%.2f reached (spent €%.2f). Raise FEEDGENIX_TRANSLATION_MONTHLY_CAP to continue.',
                $cap, $spent
            ));
        }
    }

    private function wouldExceedMonthlyCap(float $additionalCost): bool
    {
        $cap = (float) config('feedgenix.translation.monthly_cap_eur', 10.00);
        return ($this->monthlySpend() + $additionalCost) > $cap;
    }

    private function monthlySpend(): float
    {
        return (float) TranslationQuota::where('year', now()->year)
            ->where('month', now()->month)
            ->sum('cost_eur');
    }

    private function isExhausted(TranslationProviderInterface $provider, int $charsUsed, float $costUsed): bool
    {
        if ($provider->costPerMillionChars() === 0.0) {
            $limit = $provider->freeMonthlyLimit();
            return $limit > 0 && $charsUsed >= $limit;
        }
        $cap = self::PROVIDER_CAPS_EUR[$provider->name()] ?? PHP_FLOAT_MAX;
        return $costUsed >= $cap;
    }

    // ─── Circuit breaker ───────────────────────────────────────────────────

    private function recordResult(string $provider, bool $success): void
    {
        $key = "translation_health:{$provider}";
        if ($success) {
            Cache::put($key, ['failures' => 0, 'last_success' => now()->timestamp], 3600);
            return;
        }

        $health = Cache::get($key, ['failures' => 0]);
        $health['failures'] = ($health['failures'] ?? 0) + 1;
        $health['last_failure'] = now()->timestamp;
        Cache::put($key, $health, 3600);

        if ($health['failures'] >= 5) {
            Cache::put("translation_circuit_break:{$provider}", true, 600);
            Log::warning("Circuit breaker: {$provider} disabled for 10 min after {$health['failures']} failures");
        }
    }

    private function isProviderHealthy(string $provider): bool
    {
        return !Cache::get("translation_circuit_break:{$provider}", false);
    }

    private function bumpProviderStat(array &$stats, string $provider, int $count = 1): void
    {
        $stats['provider_breakdown'][$provider] = ($stats['provider_breakdown'][$provider] ?? 0) + $count;
    }
}
