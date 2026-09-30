<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Encoding;

/**
 * Detects a text feed's encoding from its first bytes. In order: a byte-order mark, the NUL
 * pattern of BOM-less UTF-16, valid UTF-8 (plain ASCII included), else Windows-1252: the encoding
 * Excel writes on Western Windows, and a superset of ISO-8859-1 for printable text.
 *
 * Legacy defect D2: "auto" detected an encoding and then converted nothing.
 */
final class EncodingDetector
{
    private const array BOMS = [
        "\xEF\xBB\xBF" => 'UTF-8',
        "\xFF\xFE\x00\x00" => 'UTF-32LE',
        "\x00\x00\xFE\xFF" => 'UTF-32BE',
        "\xFF\xFE" => 'UTF-16LE',
        "\xFE\xFF" => 'UTF-16BE',
    ];

    /** @param string $head the file's first bytes; $complete when that is the whole file */
    public static function detect(string $head, bool $complete): DetectedEncoding
    {
        foreach (self::BOMS as $bom => $name) {
            if (str_starts_with($head, $bom)) {
                return new DetectedEncoding($name, 'bom', strlen($bom));
            }
        }

        $utf16 = self::utf16Pattern($head);
        if ($utf16 !== null) {
            return new DetectedEncoding($utf16, 'utf16_pattern');
        }

        $sample = $complete ? $head : self::trimPartialUtf8($head);
        if (mb_check_encoding($sample, 'UTF-8')) {
            return new DetectedEncoding(Encodings::UTF8, preg_match('/[\x80-\xFF]/', $sample) === 1 ? 'valid_utf8' : 'ascii');
        }

        return new DetectedEncoding(Encodings::FALLBACK, 'fallback');
    }

    /**
     * Text in UTF-16 without a BOM: mostly-ASCII text has a NUL in every other byte (odd bytes
     * for little-endian, even bytes for big-endian).
     */
    private static function utf16Pattern(string $head): ?string
    {
        $bytes = substr($head, 0, 4096);
        $pairs = intdiv(strlen($bytes), 2);
        if ($pairs < 8) {
            return null;
        }

        $evenNul = 0;
        $oddNul = 0;
        for ($i = 0; $i < $pairs * 2; $i += 2) {
            $evenNul += $bytes[$i] === "\0" ? 1 : 0;
            $oddNul += $bytes[$i + 1] === "\0" ? 1 : 0;
        }

        return match (true) {
            $oddNul / $pairs > 0.3 && $evenNul / $pairs < 0.05 => 'UTF-16LE',
            $evenNul / $pairs > 0.3 && $oddNul / $pairs < 0.05 => 'UTF-16BE',
            default => null,
        };
    }

    /** Drops an incomplete UTF-8 sequence at the end of a cut-off sample. */
    public static function trimPartialUtf8(string $bytes): string
    {
        $length = strlen($bytes);
        for ($back = 1; $back <= min(4, $length); $back++) {
            $byte = ord($bytes[$length - $back]);
            if (($byte & 0xC0) === 0x80) {
                continue; // a continuation byte: keep looking for the lead byte
            }

            $needed = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $needed > $back ? substr($bytes, 0, $length - $back) : $bytes;
        }

        return $bytes;
    }
}
