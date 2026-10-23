<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Formatters;

/**
 * A description in one canonical HTML shape, for channels that render it best that way (ported
 * from the legacy DescriptionFormatter):
 *
 *     <p>{the prose, including text that floated outside any tag}</p><ul><li>{spec}</li>…</ul>
 *
 * Bullets come from the list items, or from a comma-separated "Key: Value" list of at least three
 * entries. Single-line, idempotent, and never empty for a text that was not. The legacy version
 * ran on every description of every feed; here it is a column transform a preset asks for.
 */
final class DescriptionFormatter
{
    private const int MIN_KV_ENTRIES = 3;

    private const float KV_RATIO_REQUIRED = 0.8;

    private const string KEY_VALUE = '/^\p{L}[\p{L}\p{N}\s\-\/]{0,40}:\s\S/u';

    public static function canonical(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $bullets = [];
        if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $text, $items) > 0) {
            foreach ($items[1] as $item) {
                $clean = self::inline($item);
                if ($clean !== '') {
                    $bullets[] = $clean;
                }
            }
        }
        $prose = self::inline((string) preg_replace('/<(ul|ol)\b[^>]*>.*?<\/\1>/is', ' ', $text));

        if ($bullets === [] && $prose !== '') {
            [$intro, $derived] = self::keyValueBullets($prose);
            if ($derived !== []) {
                [$prose, $bullets] = [$intro, $derived];
            }
        }

        $html = $prose === '' ? '' : '<p>'.$prose.'</p>';
        if ($bullets !== []) {
            $html .= '<ul><li>'.implode('</li><li>', $bullets).'</li></ul>';
        }
        if ($html === '') {
            $stripped = self::inline($text);
            $html = $stripped === '' ? '' : '<p>'.$stripped.'</p>';
        }

        return $html;
    }

    /** Tags removed, line breaks and runs of white space as one space. */
    private static function inline(string $html): string
    {
        $text = strip_tags((string) preg_replace('/<br\s*\/?>/i', ' ', $html));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array{0: string, 1: list<string>} the intro, and the "Key: Value" entries when the text is such a list */
    private static function keyValueBullets(string $prose): array
    {
        $parts = array_map(trim(...), preg_split('/,\s+(?=\p{L}[\p{L}\p{N}\s\-\/]{0,40}:\s)/u', $prose) ?: []);
        $intro = '';
        if ($parts !== [] && preg_match(self::KEY_VALUE, $parts[0]) !== 1) {
            $intro = array_shift($parts);
        }
        if (count($parts) < self::MIN_KV_ENTRIES) {
            return [$prose, []];
        }
        $pairs = count(array_filter($parts, static fn (string $part): bool => preg_match(self::KEY_VALUE, $part) === 1));

        return $pairs / count($parts) < self::KV_RATIO_REQUIRED ? [$prose, []] : [$intro, $parts];
    }
}
