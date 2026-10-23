<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Formatters;

/**
 * A very short description from a full one (ported from the legacy ShortDescriptionMaker): the
 * first sentences up to 160 characters, at least 20, without HTML. It runs on a description that
 * is already in the target language, so it needs no AI and no translation. The legacy version
 * knew only Latin sentence ends; Chinese, Japanese, Arabic and Devanagari ones end a sentence too,
 * and a text without spaces is cut at the limit instead of not at all.
 */
final class ShortDescription
{
    private const int MAX = 160;

    private const int MIN = 20;

    public static function make(string $description): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/<(br|\/p|\/li)\b[^>]*>/i', ' ', $description))));
        if ($text === '') {
            return '';
        }

        if (preg_match_all('/.+?(?:[.!?](?:\s|$)|[。！？؟।]\s?|$)/u', $text, $sentences) > 0) {
            // Sentences joined as written: with their own spaces, and none where the script has none.
            $short = '';
            foreach ($sentences[0] as $sentence) {
                if (mb_strlen(trim($short.$sentence)) > self::MAX) {
                    break;
                }
                $short .= $sentence;
                if (mb_strlen(trim($short)) >= self::MIN) {
                    break;
                }
            }
            $short = trim($short);
            if (mb_strlen($short) >= self::MIN) {
                return $short;
            }
        }

        if (mb_strlen($text) <= self::MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX - 1);
        // At a word boundary when the text has words; scripts without spaces are cut at the limit.
        $atWord = (string) preg_replace('/\s+\S*$/u', '', $cut);

        return rtrim($atWord === '' ? $cut : $atWord, ' ,;:—-').'…';
    }
}
