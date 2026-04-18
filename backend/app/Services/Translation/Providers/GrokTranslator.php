<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * xAI Grok fallback translator — used when free providers are exhausted.
 * Treats the model as an LLM translator with a strict numbered format to
 * keep input/output aligned.
 */
class GrokTranslator implements TranslationProviderInterface
{
    private const LANG_NAMES = [
        'en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian',
        'es' => 'Spanish', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'pl' => 'Polish',
    ];

    private const CHUNK_SIZE = 50;

    public function name(): string
    {
        return 'grok';
    }

    public function label(): string
    {
        return 'Grok (xAI)';
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.xai.api_key'));
    }

    public function freeMonthlyLimit(): int
    {
        return 0;
    }

    public function costPerMillionChars(): float
    {
        return 0.50;
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $fieldType = 'default'): array
    {
        if (empty($texts)) {
            return [];
        }

        $sourceName = self::LANG_NAMES[$sourceLang] ?? $sourceLang;
        $targetName = self::LANG_NAMES[$targetLang] ?? $targetLang;

        $results = [];
        foreach (array_chunk(array_values($texts), self::CHUNK_SIZE) as $chunk) {
            $results = array_merge($results, $this->translateChunk($chunk, $sourceName, $targetName, $fieldType));
        }
        return $results;
    }

    /** @param string[] $chunk @return string[] */
    private function translateChunk(array $chunk, string $sourceName, string $targetName, string $fieldType): array
    {
        $numbered = '';
        foreach ($chunk as $i => $text) {
            $numbered .= ($i + 1) . '. ' . $text . "\n";
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . (string) config('services.xai.api_key'),
            'Content-Type' => 'application/json',
        ])->withoutVerifying()->timeout(120)->post('https://api.x.ai/v1/chat/completions', [
            'model' => (string) config('services.xai.model', 'grok-3-mini'),
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($sourceName, $targetName, $fieldType)],
                ['role' => 'user', 'content' => "Translate:\n\n{$numbered}"],
            ],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Grok/xAI error: ' . $response->status() . ' ' . mb_substr($response->body(), 0, 200));
        }

        $content = (string) ($response->json()['choices'][0]['message']['content'] ?? '');

        $parsed = [];
        foreach (explode("\n", trim($content)) as $line) {
            if (preg_match('/^\d+\.\s*(.+)$/', trim($line), $m)) {
                $parsed[] = $m[1];
            }
        }

        if (count($parsed) !== count($chunk)) {
            throw new RuntimeException('Grok returned ' . count($parsed) . ' lines for ' . count($chunk) . ' inputs — alignment lost.');
        }

        return $parsed;
    }

    private function buildSystemPrompt(string $sourceName, string $targetName, string $fieldType): string
    {
        $base = "You translate e-commerce product text from {$sourceName} to {$targetName}. Rules:\n"
            . "1. Translate EXACTLY the same information — no additions, no omissions, no paraphrasing.\n"
            . "2. Preserve marketing tone, SEO keywords, approximate length.\n"
            . "3. Keep ALL brand names, measurements, units, numbers, technical specs unchanged.\n"
            . "4. Keep HTML tags in the same structure.\n"
            . "5. Use market-standard conventions for the target language.\n"
            . "6. When in doubt, translate literally rather than creatively.\n";

        if (str_starts_with($fieldType, 'category')) {
            $base .= "CATEGORY HIERARCHY RULES:\n"
                . "- The input is a category path like 'A > B > C' (or / or \\).\n"
                . "- Translate each segment individually, keeping the exact number and order of levels.\n"
                . "- Brand-name segments stay unchanged.\n"
                . "- Use the same separator character as the input.\n";
        }

        $base .= "Respond with ONLY the numbered translations, matching input numbers. No explanations.";
        return $base;
    }
}
