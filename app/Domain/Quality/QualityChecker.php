<?php

declare(strict_types=1);

namespace App\Domain\Quality;

use App\Domain\Mapping\LanguageGuess;

/**
 * Checks one translation against its source before it is kept (ported from the legacy
 * QualityChecker, which missed HTML, scripts and echoes, and matched numbers as substrings so that
 * "100" was found in "1000"):
 *
 *  - numbers, codes and model numbers: every one of the source's must be in the translation, as a
 *    whole token (a decimal comma may become a point and back);
 *  - HTML: the same tags in the same order;
 *  - script: written in the target language's script (Greek, Cyrillic, CJK, Arabic, Hebrew…);
 *  - category paths: the same number of levels;
 *  - length: between 0.3 and 3 times the source, for sources of 20 characters or more;
 *  - echo: a source of three words or more must not come back unchanged;
 *  - brand-like tokens (CamelCase, capitals with digits) kept, when the source has two or more.
 *
 * A hard failure (numbers, HTML, script, levels, or two failures) is not published; one other
 * failure is published and flagged for review.
 */
final class QualityChecker
{
    private const string NUMBER = '/(?<![\p{L}\p{N}])(?:\d+(?:[.,]\d+)*|[\p{Lu}]{2,}\d+[\p{Lu}\d-]*|\d+[\p{Lu}]{2,}[\p{Lu}\d]*)(?![\p{N}])/u';

    private const string BRAND = '/(?<![\p{L}])(?:[A-Z][a-z]+[A-Z][A-Za-z]*|[a-z]+[A-Z][A-Za-z]+|[A-Z]{2,}[0-9][A-Z0-9]*)(?![\p{L}])/u';

    public static function check(string $field, string $source, string $translation, string $targetLocale): QualityResult
    {
        $failures = [];
        $translation = trim($translation);
        if ($translation === '') {
            return new QualityResult(['empty'], ['empty']);
        }

        $missing = self::missingNumbers($source, $translation);
        if ($missing !== []) {
            $failures[] = 'numbers';
        }
        if (self::tags($source) !== self::tags($translation)) {
            $failures[] = 'html';
        }
        if (LanguageGuess::matchesScript(strip_tags($translation), $targetLocale) === false) {
            $failures[] = 'script';
        }
        if ($field === 'category' && self::levels($source) !== self::levels($translation)) {
            $failures[] = 'levels';
        }
        $sourceLength = mb_strlen(trim(strip_tags($source)));
        $ratio = $sourceLength === 0 ? 1.0 : mb_strlen(strip_tags($translation)) / $sourceLength;
        if ($sourceLength >= 20 && ($ratio < 0.3 || $ratio > 3.0)) {
            $failures[] = 'length';
        }
        if (self::words($source) >= 3 && mb_strtolower(trim($source)) === mb_strtolower($translation)) {
            $failures[] = 'echo';
        }
        if (! in_array($field, ['description', 'category'], true) && self::brandsLost($source, $translation)) {
            $failures[] = 'brands';
        }

        $hard = array_values(array_intersect($failures, ['numbers', 'html', 'script', 'levels']));
        if (count($failures) >= 2 && $hard === []) {
            $hard = $failures;
        }

        return new QualityResult($failures, $hard);
    }

    /** @return list<string> source numbers and codes the translation does not have */
    private static function missingNumbers(string $source, string $translation): array
    {
        $normalise = static fn (string $token): string => strtoupper(str_replace(',', '.', $token));
        $have = array_count_values(array_map($normalise, self::tokens(self::NUMBER, strip_tags($translation))));
        $missing = [];
        foreach (self::tokens(self::NUMBER, strip_tags($source)) as $token) {
            $key = $normalise($token);
            if (($have[$key] ?? 0) > 0) {
                $have[$key]--;
            } else {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private static function tokens(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches);

        return array_map(strval(...), $matches[0]);
    }

    /** @return list<string> the tag names in order, "/p" for closing ones */
    private static function tags(string $html): array
    {
        preg_match_all('~<\s*(/?)\s*([a-z][a-z0-9]*)\b[^>]*>~i', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => $match[1].strtolower($match[2]), $matches);
    }

    private static function levels(string $path): int
    {
        return (int) preg_match_all('~\s*(?:>|/|\\\\)\s*~', $path);
    }

    private static function words(string $text): int
    {
        return count(preg_split('/[\s\p{Z}]+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function brandsLost(string $source, string $translation): bool
    {
        $brands = array_unique(self::tokens(self::BRAND, $source));
        if (count($brands) < 2) {
            return false;
        }

        return array_filter($brands, static fn (string $brand): bool => str_contains($translation, $brand)) === [];
    }
}
