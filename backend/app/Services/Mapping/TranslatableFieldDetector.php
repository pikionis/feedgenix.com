<?php

declare(strict_types=1);

namespace App\Services\Mapping;

/**
 * Classifies CSV columns by the kind of content their sample values contain:
 * translatable prose, enum/code tokens, pure numbers, empty. Used by the
 * FieldMappingService to decide which columns to send to translation.
 */
class TranslatableFieldDetector
{
    public const KIND_TRANSLATABLE = 'translatable';
    public const KIND_CODE         = 'code';          // SKU-like tokens, short enums, size codes
    public const KIND_NUMERIC      = 'numeric';       // pure numbers or formatted numbers
    public const KIND_EMPTY        = 'empty';         // no meaningful content in samples

    private const NON_TRANSLATABLE_THRESHOLD = 0.7;   // if >=70% of samples are codes/numbers → not translatable
    private const SIZE_CODE_RE    = '/^(XXS|XS|S|M|L|XL|XXL|XXXL|TU|\d{2,3}(\/\d{2,3})?)$/i';
    private const ENUM_TOKEN_RE   = '/^[a-z][a-z0-9]*_[a-z0-9_]+$/i';        // gender_male, color_red, …
    private const NUMERIC_VALUE_RE = '/^-?\d+(\.\d+)?$/';
    private const MONEY_RE         = '/^-?\d+(\.\d+)?\s*(€|EUR|\$|USD|CHF|£|GBP)?$/i';

    /**
     * Classify a single column from its sample values.
     *
     * @param  array<int, mixed> $samples raw values from the column (any type)
     * @return array{kind: string, confidence: float, translatable_ratio: float, non_empty_count: int}
     */
    public function classify(array $samples): array
    {
        $nonEmpty = [];
        foreach ($samples as $s) {
            $v = trim((string) ($s ?? ''));
            if ($v !== '') {
                $nonEmpty[] = $v;
            }
        }
        $total = count($nonEmpty);

        if ($total === 0) {
            return [
                'kind' => self::KIND_EMPTY,
                'confidence' => 1.0,
                'translatable_ratio' => 0.0,
                'non_empty_count' => 0,
            ];
        }

        $numericCount = 0;
        $codeCount = 0;
        $translatableCount = 0;

        foreach ($nonEmpty as $v) {
            if (preg_match(self::NUMERIC_VALUE_RE, $v) || preg_match(self::MONEY_RE, $v)) {
                $numericCount++;
                continue;
            }
            if (preg_match(self::SIZE_CODE_RE, $v) || preg_match(self::ENUM_TOKEN_RE, $v)) {
                $codeCount++;
                continue;
            }
            // Very short (≤3 chars, no whitespace) = probably a code
            if (mb_strlen($v) <= 3 && !preg_match('/\s/', $v)) {
                $codeCount++;
                continue;
            }
            $translatableCount++;
        }

        $translatableRatio = $translatableCount / $total;

        // Numbers dominate
        if ($numericCount / $total >= self::NON_TRANSLATABLE_THRESHOLD) {
            return [
                'kind' => self::KIND_NUMERIC,
                'confidence' => min(1.0, $numericCount / $total),
                'translatable_ratio' => $translatableRatio,
                'non_empty_count' => $total,
            ];
        }

        // Codes dominate (or codes+numbers together dominate)
        if (($codeCount + $numericCount) / $total >= self::NON_TRANSLATABLE_THRESHOLD) {
            return [
                'kind' => self::KIND_CODE,
                'confidence' => min(1.0, ($codeCount + $numericCount) / $total),
                'translatable_ratio' => $translatableRatio,
                'non_empty_count' => $total,
            ];
        }

        return [
            'kind' => self::KIND_TRANSLATABLE,
            'confidence' => $translatableRatio,
            'translatable_ratio' => $translatableRatio,
            'non_empty_count' => $total,
        ];
    }

    /**
     * Convenience: classify every column in one pass.
     *
     * @param  array<int, array<string, mixed>> $sampleRows
     * @param  string[]                         $columns
     * @return array<string, array{kind: string, confidence: float, translatable_ratio: float, non_empty_count: int}>
     */
    public function classifyAll(array $sampleRows, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $values = array_map(fn ($row) => $row[$col] ?? null, $sampleRows);
            $out[$col] = $this->classify($values);
        }
        return $out;
    }

    /**
     * Keyword-based language guess. No network calls.
     * Returns one of 'fr', 'de', 'it', 'es', 'en' (en is the default if nothing matches).
     * Returns null for very short input.
     */
    public function guessLanguage(string $text): ?string
    {
        if (mb_strlen($text) < 5) {
            return null;
        }

        $patterns = [
            'de' => '/\b(und|mit|für|der|die|das|ist|nicht|oder|auch|größe|farbe|aus|ein|eine|haben|werden|auf|von|zur|zum|bei|über)\b/ui',
            'fr' => '/\b(les|des|une|avec|pour|dans|est|pas|très|cette|couleur|sont|tout|qui|que|nous|aux|sur|par|mais|ses|son|leur|du|de|le|la)\b/ui',
            'it' => '/\b(della|delle|nella|sono|questo|questa|colore|anche|molto|gli|con|per|che|non|una|del|dei|nel|dal|alla)\b/ui',
            'es' => '/\b(los|las|una|con|por|para|del|que|está|son|este|esta|muy|como|pero|más|sobre|entre|hasta|desde)\b/ui',
        ];

        $scores = [];
        foreach ($patterns as $lang => $pattern) {
            $scores[$lang] = preg_match_all($pattern, $text);
        }
        arsort($scores);
        $top = (string) key($scores);
        $threshold = mb_strlen($text) < 30 ? 1 : 2;

        return ($scores[$top] ?? 0) >= $threshold ? $top : 'en';
    }

    /**
     * Aggregate per-column text and pick the most likely source language
     * for the dataset as a whole. Only translatable columns are considered.
     *
     * @param  array<int, array<string, mixed>> $sampleRows
     * @param  string[]                         $translatableColumns
     */
    public function detectDominantLanguage(array $sampleRows, array $translatableColumns): ?string
    {
        $buffer = '';
        foreach ($translatableColumns as $col) {
            foreach ($sampleRows as $row) {
                $val = trim((string) ($row[$col] ?? ''));
                if ($val === '') {
                    continue;
                }
                $buffer .= ' ' . $val;
                if (mb_strlen($buffer) > 4000) {
                    break 2;
                }
            }
        }

        return $this->guessLanguage(trim($buffer));
    }
}
