<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * Which registry language a text is in, or null when it cannot tell. Non-Latin scripts decide by
 * themselves; Latin-script languages by their most frequent short words. It only suggests a
 * locale for the reviewer to confirm; it never decides alone what gets translated.
 */
final class LanguageGuess
{
    /** @var array<string, list<string>> */
    private const array STOPWORDS = [
        'en' => ['the', 'and', 'with', 'for', 'of', 'in', 'is', 'to', 'this', 'your', 'from', 'made'],
        'de' => ['der', 'die', 'das', 'und', 'mit', 'für', 'ist', 'aus', 'ein', 'eine', 'nicht', 'zum', 'auf', 'bei'],
        'fr' => ['le', 'la', 'les', 'et', 'avec', 'pour', 'des', 'du', 'une', 'est', 'en', 'sur', 'dans'],
        'it' => ['il', 'la', 'di', 'e', 'con', 'per', 'del', 'della', 'una', 'è', 'che', 'nel'],
        'es' => ['el', 'la', 'los', 'las', 'y', 'con', 'para', 'del', 'una', 'es', 'que', 'por'],
        'nl' => ['de', 'het', 'en', 'met', 'voor', 'van', 'een', 'is', 'op', 'niet', 'uit'],
        'pt' => ['o', 'a', 'os', 'as', 'e', 'com', 'para', 'do', 'da', 'uma', 'é', 'em', 'não'],
        'pl' => ['i', 'w', 'z', 'na', 'do', 'dla', 'jest', 'się', 'nie', 'oraz', 'ze'],
        'sv' => ['och', 'med', 'för', 'av', 'en', 'ett', 'är', 'på', 'som', 'till'],
        'da' => ['og', 'med', 'til', 'af', 'en', 'et', 'er', 'på', 'som', 'den'],
        'cs' => ['a', 'v', 'na', 's', 'pro', 'je', 'se', 'z', 'do', 'ze'],
        'tr' => ['ve', 'ile', 'için', 'bir', 'bu', 'da', 'de', 'çok'],
    ];

    public static function guess(string $text): ?string
    {
        $text = trim(strip_tags($text));
        if (mb_strlen($text) < 12) {
            return null;
        }

        return self::byScript($text) ?? self::byWords($text);
    }

    /**
     * Whether $text is written in the script $locale uses. Null when the locale's script is Latin
     * (any Latin text could be in it) or the text has no letters.
     */
    public static function matchesScript(string $text, string $locale): ?bool
    {
        $script = self::scriptOf($locale);
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === false || $letters === 0) {
            return null;
        }
        if ($script === null) {
            $latin = (int) preg_match_all('/\p{Latin}/u', $text);

            return $latin * 2 >= $letters;
        }
        $inScript = (int) preg_match_all('/'.$script.'/u', $text);

        return $inScript * 2 >= $letters;
    }

    private static function scriptOf(string $locale): ?string
    {
        return match (strtolower(explode('-', $locale)[0])) {
            'el' => '\p{Greek}',
            'bg', 'uk', 'ru', 'sr', 'mk' => '\p{Cyrillic}',
            'ar' => '\p{Arabic}',
            'he' => '\p{Hebrew}',
            'ja' => '[\p{Hiragana}\p{Katakana}\p{Han}]',
            'ko' => '\p{Hangul}',
            'zh' => '\p{Han}',
            'th' => '\p{Thai}',
            default => null,
        };
    }

    private static function byScript(string $text): ?string
    {
        $letters = max(1, (int) preg_match_all('/\p{L}/u', $text));
        $share = static fn (string $class): float => (int) preg_match_all('/'.$class.'/u', $text) / $letters;

        return match (true) {
            $share('\p{Greek}') > 0.5 => 'el',
            $share('\p{Cyrillic}') > 0.5 => preg_match('/[іїєґ]/iu', $text) === 1 ? 'uk' : (preg_match('/[ъ]/iu', $text) === 1 ? 'bg' : null),
            $share('[\p{Hiragana}\p{Katakana}]') > 0.1 => 'ja',
            $share('\p{Hangul}') > 0.5 => 'ko',
            $share('\p{Han}') > 0.5 => preg_match('/[这们个说时发后国为]/u', $text) === 1 ? 'zh-Hans' : (preg_match('/[這們個說時發後國為]/u', $text) === 1 ? 'zh-Hant' : null),
            $share('\p{Arabic}') > 0.5 => 'ar',
            $share('\p{Hebrew}') > 0.5 => 'he',
            default => null,
        };
    }

    private static function byWords(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 3) {
            return null;
        }
        $counts = array_count_values($words);

        $scores = [];
        foreach (self::STOPWORDS as $language => $stopwords) {
            $scores[$language] = array_sum(array_intersect_key($counts, array_flip($stopwords)));
        }
        arsort($scores);
        $ranked = array_keys($scores);
        $best = $scores[$ranked[0]];
        $second = $scores[$ranked[1]];

        // At least two hits, and clearly ahead of the runner-up.
        return $best >= 2 && $best >= $second * 1.5 ? $ranked[0] : null;
    }
}
