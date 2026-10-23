<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Catalog\Schema\CanonicalField;
use App\Domain\Catalog\Schema\FieldKind;
use App\Domain\Ingest\Profile\ValueKind;
use App\Domain\Mapping\Data\Validation;

/**
 * Do a column's values fit a field? Ported from the legacy FieldValidator, which only knew Latin
 * letters (legacy D8: `[a-zA-ZÀ-ÿ]`, so every Greek, Cyrillic or Japanese title "had no letters")
 * and counted words with str_word_count, which is Latin-only too. Letters are \p{L} here, words are
 * Unicode, and a localized field also checks that the values are written in its locale's script.
 *
 * A rule that 30% or more of the values break is an error: the field is left empty rather than wrong.
 */
final class FieldValidator
{
    private const float PROBLEM_SHARE = 0.3;

    private const string URL = '#https?://|www\.#i';

    private const string IMAGE_PATH = '#\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?)(?:\?|$|[\s;,])#i';

    private const string SIZE = '/^(?:XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL|TU|UNI|ONE ?SIZE|\d{2,3}(?:[\/.\-]\d{2,3})?)$/iu';

    private const string TAXONOMY_ID = '/^\d+\s*\|\s*\S/u';

    /**
     * Text rules per field: [min length, max length, needs letters, rejects pure numbers, min words, detects sizes].
     *
     * @var array<string, array{0: int, 1: int, 2: bool, 3: bool, 4: int, 5: bool}>
     */
    private const array TEXT_RULES = [
        'title' => [2, 300, true, true, 1, true],
        'description' => [5, 20000, true, true, 2, false],
        'category' => [2, 300, true, true, 1, true],
        'color' => [2, 100, true, true, 1, true],
        'size' => [1, 100, false, false, 1, false],
        'warranty' => [1, 500, false, false, 1, false],
        'features' => [2, 10000, true, false, 1, false],
        'material' => [2, 1000, true, true, 1, true],
        'care_instructions' => [3, 4000, true, true, 1, false],
        'dimensions_text' => [1, 500, false, false, 1, false],
        'other_attributes' => [1, 10000, false, false, 1, false],
        'brand' => [1, 120, true, false, 1, false],
    ];

    /** @var list<string> ISO 4217 codes that feeds commonly carry. */
    private const array CURRENCIES = ['CHF', 'EUR', 'USD', 'GBP', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'TRY', 'JPY', 'CNY', 'KRW', 'AUD', 'CAD', 'NZD', 'ILS', 'AED', 'SAR', 'BRL', 'MXN', 'INR', 'UAH', 'ISK'];

    /**
     * @param  list<string>  $values  the column's sample values
     */
    public static function validate(CanonicalField $field, array $values, ?string $locale = null): Validation
    {
        $values = array_values(array_filter(array_map(trim(...), $values), static fn (string $value): bool => $value !== ''));
        $count = count($values);
        if ($count === 0) {
            return Validation::of([], 0);
        }

        $issues = match ($field->kind) {
            FieldKind::Gtin => self::share($values, static fn (string $v): bool => ! ValueKind::isGtin(preg_replace('/\s+/', '', $v) ?? $v), 'invalid_gtin', 'are not valid GTIN/EAN numbers (check digit)', 0.1),
            FieldKind::Money => self::share($values, static fn (string $v): bool => self::money($v) === null, 'not_a_price', 'are not prices', 0.1),
            FieldKind::Currency => self::share($values, static fn (string $v): bool => ! in_array(strtoupper($v), self::CURRENCIES, true) && ! in_array($v, ['€', '$', '£', '¥', 'Fr.', 'CHF'], true), 'not_a_currency', 'are not currency codes', 0.1),
            FieldKind::Integer => self::share($values, static fn (string $v): bool => preg_match('/^-?\d+$/', $v) !== 1, 'not_an_integer', 'are not whole numbers', 0.1),
            FieldKind::Url => self::share($values, static fn (string $v): bool => preg_match('~^https?://\S+$~i', $v) !== 1, 'not_a_url', 'are not web addresses', 0.1),
            FieldKind::Urls => self::share($values, static fn (string $v): bool => array_filter(preg_split('/[\s,;|]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $url): bool => preg_match('~^https?://\S+$~i', $url) !== 1) !== [], 'not_urls', 'are not lists of web addresses', 0.1),
            FieldKind::Code => self::code($values),
            FieldKind::Text, FieldKind::Html, FieldKind::Category, FieldKind::Number => self::text($field, $values),
        };

        if ($locale !== null && $field->isLocalized()) {
            array_push($issues, ...self::script($values, $locale));
        }

        return Validation::of($issues, $count);
    }

    /** "19.90", "19,90 €", "CHF 1'299.00", "1.299,00" → a decimal string, or null. */
    public static function money(string $value): ?string
    {
        $number = trim((string) preg_replace('/^(?:[A-Z]{3}|[€$£¥]|Fr\.)\s*|\s*(?:[A-Z]{3}|[€$£¥]|Fr\.|\.-|-)$/u', '', trim($value)));
        if (preg_match('/^\d{1,3}(?:[\'’ .]\d{3})*(?:[.,]\d{1,2})?$|^\d+(?:[.,]\d{1,4})?$/u', $number) !== 1) {
            return null;
        }

        return $number;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}>
     */
    private static function text(CanonicalField $field, array $values): array
    {
        [$min, $max, $letters, $noPureNumbers, $minWords, $sizes] = self::TEXT_RULES[$field->key] ?? [1, 2000, false, false, 1, false];
        $issues = [];
        $count = count($values);

        $checks = [
            ['contains_url', 'error', 'contain web addresses: it is probably a link or image column', static fn (string $v): bool => $field->key !== 'description' && preg_match(self::URL, $v) === 1],
            ['contains_image_path', 'error', 'look like image files', static fn (string $v): bool => preg_match(self::IMAGE_PATH, $v) === 1],
            ['contains_gtin', 'error', 'look like barcodes', ValueKind::isGtin(...)],
            ['pure_numbers', 'error', 'are numbers, not text', static fn (string $v): bool => $noPureNumbers && preg_match('/^[\d.,\s\'’]+$/u', $v) === 1],
            ['no_letters', 'error', 'have no letters', static fn (string $v): bool => $letters && preg_match('/\p{L}/u', $v) !== 1],
            ['too_short', 'warning', "are shorter than {$min} characters", static fn (string $v): bool => mb_strlen($v) < $min],
            ['too_long', 'info', "are longer than {$max} characters", static fn (string $v): bool => mb_strlen($v) > $max],
            ['too_few_words', 'warning', "have fewer than {$minWords} words", static fn (string $v): bool => count(preg_split('/[\s\p{Z}]+/u', strip_tags($v), -1, PREG_SPLIT_NO_EMPTY) ?: []) < $minWords && ! self::isCjk($v)],
            ['looks_like_sizes', 'error', 'look like sizes (M, XL, 42): they belong in Size', static fn (string $v): bool => $sizes && preg_match(self::SIZE, $v) === 1],
            ['taxonomy_id', 'error', 'are taxonomy ids ("3 | Leisure"), not category names', static fn (string $v): bool => $field->key === 'category' && preg_match(self::TAXONOMY_ID, $v) === 1],
        ];

        foreach ($checks as [$type, $severity, $message, $test]) {
            $hits = count(array_filter($values, $test));
            if ($hits === 0) {
                continue;
            }
            // A rule broken by a few values is a warning; by 30% or more, its own severity.
            $level = $severity === 'error' && $hits < max(1, (int) ceil($count * self::PROBLEM_SHARE)) ? 'warning' : $severity;
            $issues[] = ['type' => $type, 'severity' => $level, 'count' => $hits, 'message' => "{$hits} of {$count} values {$message}."];
        }

        return $issues;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}>
     */
    private static function code(array $values): array
    {
        $issues = self::share($values, static fn (string $v): bool => mb_strlen($v) > 128, 'too_long', 'are longer than 128 characters', 0.1);
        $count = count($values);
        $distinct = count(array_unique($values));
        if ($distinct < $count) {
            $issues[] = ['type' => 'duplicates', 'severity' => 'warning', 'count' => $count - $distinct, 'message' => ($count - $distinct)." of {$count} values repeat: an id should be unique."];
        }

        return $issues;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}>
     */
    private static function script(array $values, string $locale): array
    {
        $outside = count(array_filter($values, static fn (string $v): bool => LanguageGuess::matchesScript(strip_tags($v), $locale) === false));
        if ($outside * 2 < count($values)) {
            return [];
        }

        return [['type' => 'wrong_script', 'severity' => 'warning', 'count' => $outside, 'message' => "{$outside} of ".count($values)." values are not written in {$locale}'s script."]];
    }

    /**
     * @param  list<string>  $values
     * @param  callable(string): bool  $bad
     * @return list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}>
     */
    private static function share(array $values, callable $bad, string $type, string $message, float $errorShare): array
    {
        $count = count($values);
        $hits = count(array_filter($values, $bad));
        if ($hits === 0) {
            return [];
        }

        return [['type' => $type, 'severity' => $hits > $count * $errorShare ? 'error' : 'warning', 'count' => $hits, 'message' => "{$hits} of {$count} values {$message}."]];
    }

    private static function isCjk(string $value): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}]/u', $value) === 1;
    }
}
