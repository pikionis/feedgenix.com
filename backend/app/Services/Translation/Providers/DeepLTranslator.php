<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepLTranslator implements TranslationProviderInterface
{
    /** Map our 2-letter ISO codes to DeepL's target codes (they require region for EN/PT). */
    private const LANG_MAP = [
        'en' => 'EN-GB',
        'pt' => 'PT-PT',
    ];

    public function name(): string
    {
        return 'deepl';
    }

    public function label(): string
    {
        return 'DeepL';
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.deepl.api_key'));
    }

    /** Free tier is the free API plan (not per-month characters from DeepL itself). */
    public function freeMonthlyLimit(): int
    {
        return $this->isFree() ? 500_000 : 0;
    }

    public function costPerMillionChars(): float
    {
        return $this->isFree() ? 0.0 : 20.0;
    }

    public function isFree(): bool
    {
        return (bool) config('services.deepl.free', true);
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $fieldType = 'default'): array
    {
        if (empty($texts)) {
            return [];
        }

        $baseUrl = $this->isFree()
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key ' . (string) config('services.deepl.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($baseUrl, [
            'text' => array_values($texts),
            'source_lang' => strtoupper($sourceLang),
            'target_lang' => self::LANG_MAP[$targetLang] ?? strtoupper($targetLang),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('DeepL error: ' . $response->status() . ' ' . mb_substr($response->body(), 0, 200));
        }

        $translations = $response->json()['translations'] ?? [];
        return array_map(fn ($t) => (string) ($t['text'] ?? ''), $translations);
    }
}
