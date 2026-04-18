<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use Illuminate\Support\Facades\DB;

/**
 * Team-scoped translation memory, backed by the `translations` table.
 *
 * We don't keep a separate cache table — every translated cell a team has ever produced
 * is already a row, indexed on (source_hash, target_language). To serve a cache hit
 * we find any prior translation inside the same team with the same source_hash +
 * target_language, and reuse its translated_text. Lookups never cross team boundaries.
 */
class TranslationCache
{
    /**
     * Compute the canonical hash used for cache keys.
     * Whitespace is trimmed but casing and punctuation matter — two texts that
     * look the same to a human but differ byte-for-byte should miss the cache,
     * because translators actually produce different output for them.
     */
    public static function hash(string $sourceText): string
    {
        return hash('sha256', trim($sourceText));
    }

    /**
     * Fetch a cached translation for a team. Returns null on miss.
     *
     * @return array{translated_text: string, provider: string, quality_score: ?float}|null
     */
    public function get(string $sourceText, string $targetLanguage, int $teamId): ?array
    {
        $hash = self::hash($sourceText);

        $row = DB::table('translations as t')
            ->join('products as p', 'p.id', '=', 't.product_id')
            ->join('projects as pr', 'pr.id', '=', 'p.project_id')
            ->where('pr.team_id', $teamId)
            ->where('t.source_hash', $hash)
            ->where('t.target_language', $targetLanguage)
            ->whereNotNull('t.translated_text')
            ->orderByDesc('t.id')
            ->limit(1)
            ->first(['t.translated_text', 't.provider', 't.quality_score']);

        if (!$row) {
            return null;
        }

        return [
            'translated_text' => (string) $row->translated_text,
            'provider' => (string) $row->provider,
            'quality_score' => $row->quality_score !== null ? (float) $row->quality_score : null,
        ];
    }

    /**
     * Batch variant — returns map of source_text => translation|null for misses.
     * Useful to resolve hits for a whole product column in one query.
     *
     * @param  string[] $sourceTexts
     * @return array<string, array{translated_text: string, provider: string, quality_score: ?float}|null>
     */
    public function getBatch(array $sourceTexts, string $targetLanguage, int $teamId): array
    {
        if (empty($sourceTexts)) {
            return [];
        }

        $hashToText = [];
        foreach ($sourceTexts as $text) {
            $hashToText[self::hash($text)] = $text;
        }

        $rows = DB::table('translations as t')
            ->join('products as p', 'p.id', '=', 't.product_id')
            ->join('projects as pr', 'pr.id', '=', 'p.project_id')
            ->where('pr.team_id', $teamId)
            ->where('t.target_language', $targetLanguage)
            ->whereIn('t.source_hash', array_keys($hashToText))
            ->whereNotNull('t.translated_text')
            ->orderByDesc('t.id')
            ->get(['t.source_hash', 't.translated_text', 't.provider', 't.quality_score']);

        $byHash = [];
        foreach ($rows as $row) {
            // Keep only the first (newest, since we ordered by id desc) hit per hash.
            if (!isset($byHash[$row->source_hash])) {
                $byHash[$row->source_hash] = [
                    'translated_text' => (string) $row->translated_text,
                    'provider' => (string) $row->provider,
                    'quality_score' => $row->quality_score !== null ? (float) $row->quality_score : null,
                ];
            }
        }

        $out = [];
        foreach ($sourceTexts as $text) {
            $out[$text] = $byHash[self::hash($text)] ?? null;
        }
        return $out;
    }

    /**
     * Persist a translation result. If we already reused a cached entry, pass
     * $isCached=true so billing can distinguish paid vs. cached work.
     */
    public function put(
        int $productId,
        string $fieldName,
        string $sourceText,
        string $targetLanguage,
        string $translatedText,
        string $provider,
        bool $isCached = false,
        ?float $qualityScore = null,
    ): Translation {
        return Translation::create([
            'product_id' => $productId,
            'field_name' => $fieldName,
            'source_text' => $sourceText,
            'source_hash' => self::hash($sourceText),
            'target_language' => $targetLanguage,
            'translated_text' => $translatedText,
            'provider' => $provider,
            'quality_score' => $qualityScore,
            'is_cached' => $isCached,
        ]);
    }

    /**
     * Count unique cache entries available to a team — useful for dashboard stats.
     */
    public function sizeForTeam(int $teamId): int
    {
        return (int) DB::table('translations as t')
            ->join('products as p', 'p.id', '=', 't.product_id')
            ->join('projects as pr', 'pr.id', '=', 'p.project_id')
            ->where('pr.team_id', $teamId)
            ->whereNotNull('t.translated_text')
            ->distinct()
            ->count(DB::raw('t.source_hash || t.target_language'));
    }

}
