<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Whether a source text is really empty: blank, placeholder words feeds write for "nothing", or
 * markup with no text in it. The legacy app had two versions that disagreed (one knew placeholders,
 * the other HTML), so "n/a" was translated and "<p>&nbsp;</p>" counted as content. Only texts are
 * judged this way; golden values are kept exactly, "0" and "n/a" included (rule 1).
 */
final class EmptyValue
{
    private const array PLACEHOLDERS = ['-', '--', '---', '.', 'n/a', 'na', 'n.a.', 'null', 'none', 'nil', 'tbd', 'tba', 'k.a.', 'keine angabe', 'aucun', 'nessuno'];

    public static function isEmpty(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $text));

        return $text === '' || in_array(mb_strtolower($text), self::PLACEHOLDERS, true);
    }
}
