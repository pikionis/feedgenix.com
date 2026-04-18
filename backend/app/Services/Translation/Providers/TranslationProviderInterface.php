<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

interface TranslationProviderInterface
{
    /** Short stable id: 'microsoft', 'deepl', 'grok'. Used as the provider column in translations/quotas. */
    public function name(): string;

    /** Human label for UI/reports. */
    public function label(): string;

    /** True if required API keys/endpoints are set in config. */
    public function isConfigured(): bool;

    /** Free-tier character allowance per calendar month. 0 = no free tier (paid only). */
    public function freeMonthlyLimit(): int;

    /** EUR cost per 1,000,000 chars. 0 for free-tier-only providers. */
    public function costPerMillionChars(): float;

    /**
     * Translate a batch in a single call (or minimal calls). Texts come back in the same
     * order as they went in. Throws RuntimeException on provider error.
     *
     * @param  string[] $texts
     * @return string[]
     */
    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $fieldType = 'default'): array;
}
