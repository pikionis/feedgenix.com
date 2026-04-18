<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftTranslator implements TranslationProviderInterface
{
    public function name(): string
    {
        return 'microsoft';
    }

    public function label(): string
    {
        return 'Microsoft Translator';
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.microsoft_translator.key'))
            && !empty(config('services.microsoft_translator.endpoint'));
    }

    public function freeMonthlyLimit(): int
    {
        return 2_000_000;
    }

    public function costPerMillionChars(): float
    {
        return 0.0;
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $fieldType = 'default'): array
    {
        if (empty($texts)) {
            return [];
        }

        $endpoint = rtrim((string) config('services.microsoft_translator.endpoint'), '/');
        $url = $endpoint . '/translate?api-version=3.0&from=' . urlencode($sourceLang) . '&to=' . urlencode($targetLang);

        $body = array_map(fn ($t) => ['Text' => $t], array_values($texts));

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => (string) config('services.microsoft_translator.key'),
            'Ocp-Apim-Subscription-Region' => (string) config('services.microsoft_translator.region', 'westeurope'),
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($url, $body);

        if (!$response->successful()) {
            throw new RuntimeException('Microsoft Translator error: ' . $response->status() . ' ' . mb_substr($response->body(), 0, 200));
        }

        return array_map(
            fn ($item) => (string) ($item['translations'][0]['text'] ?? ''),
            $response->json()
        );
    }
}
