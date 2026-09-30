<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Encoding;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Format;
use App\Domain\Ingest\Warnings;
use RuntimeException;
use UConverter;

/**
 * Streams a text feed into UTF-8, one megabyte at a time, whatever its size.
 *
 * - The byte-order mark is dropped.
 * - Chunks end on whole characters: a UTF-16 surrogate pair or a UTF-8 sequence split by the chunk
 *   boundary is carried into the next chunk.
 * - Bytes that are not valid in the source encoding become U+FFFD and are counted, never dropped
 *   silently.
 * - NUL bytes are removed and counted: PostgreSQL text cannot hold them.
 * - An XML declaration is rewritten to say version 1.0 and UTF-8, which is now true (libxml reads
 *   no XML 1.1, and would otherwise decode the converted bytes a second time).
 */
final class Utf8Normalizer
{
    private const CHUNK = 1 << 20;

    private const string REPLACEMENT = "\u{FFFD}";

    public static function normalize(string $source, string $destination, DetectedEncoding $encoding, Format $format, Warnings $warnings): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the feed for conversion.');
        }

        try {
            if ($encoding->bomBytes > 0) {
                fseek($in, $encoding->bomBytes);
            }

            $unit = Encodings::unitBytes($encoding->name);
            $converter = Encodings::converter($encoding->name);
            $carry = '';
            $first = true;
            $replaced = 0;
            $nulBytes = 0;

            while (true) {
                $read = fread($in, self::CHUNK);
                $atEnd = $read === false || $read === '';
                $chunk = $carry.($atEnd ? '' : $read);
                if ($chunk === '') {
                    break;
                }

                [$chunk, $carry] = $atEnd ? [$chunk, ''] : self::split($chunk, $encoding->name, $unit);
                if ($chunk === '') {
                    continue;
                }

                $text = self::convert($chunk, $encoding, $converter, $replaced);
                $nulBytes += substr_count($text, "\0");
                $text = str_replace("\0", '', $text);

                if ($first) {
                    $first = false;
                    if ($format === Format::Xml) {
                        $text = self::fixXmlDeclaration($text);
                    }
                }

                if (fwrite($out, $text) !== strlen($text)) {
                    throw new RuntimeException('Cannot write the converted feed (is the disk full?).');
                }

                if ($atEnd) {
                    break;
                }
            }

            if ($replaced > 0) {
                $warnings->add('invalid_characters', "{$replaced} characters were not valid {$encoding->name} and were replaced with �. If the text looks wrong, choose the file's encoding.", $replaced);
            }

            if ($nulBytes > 0) {
                $warnings->add('nul_bytes', "{$nulBytes} NUL bytes were removed.", $nulBytes);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Splits a chunk so the first part ends on a whole character; the rest is carried over.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $chunk, string $encoding, int $unit): array
    {
        if ($unit === 1) {
            if ($encoding !== Encodings::UTF8) {
                return [$chunk, ''];
            }

            $whole = EncodingDetector::trimPartialUtf8($chunk);

            return [$whole, substr($chunk, strlen($whole))];
        }

        $cut = strlen($chunk) - (strlen($chunk) % $unit);
        if ($unit === 2 && $cut >= 2) {
            // A high surrogate (D800–DBFF) needs the unit after it.
            $last = $encoding === 'UTF-16LE'
                ? ord($chunk[$cut - 1])
                : ord($chunk[$cut - 2]);
            if ($last >= 0xD8 && $last <= 0xDB) {
                $cut -= 2;
            }
        }

        return [substr($chunk, 0, $cut), substr($chunk, $cut)];
    }

    private static function convert(string $chunk, DetectedEncoding $encoding, string $converter, int &$replaced): string
    {
        if ($encoding->isUtf8() && mb_check_encoding($chunk, 'UTF-8')) {
            return $chunk;
        }

        $already = substr_count($chunk, self::REPLACEMENT);
        $text = UConverter::transcode($chunk, 'UTF-8', $converter);
        if (intl_is_failure(intl_get_error_code())) {
            throw UnreadableFeed::because(UnreadableReason::UnknownEncoding, "The file could not be converted from {$encoding->name}. Choose its encoding and try again.");
        }

        if ($encoding->isUtf8() || Encodings::unitBytes($encoding->name) > 1) {
            $replaced += max(0, substr_count($text, self::REPLACEMENT) - $already);
        }

        return $text;
    }

    private static function fixXmlDeclaration(string $text): string
    {
        $end = strpos($text, '?>');
        if (! str_starts_with($text, '<?xml') || $end === false) {
            return $text;
        }

        $declaration = substr($text, 0, $end + 2);
        $fixed = (string) preg_replace('/\bencoding\s*=\s*(["\'])[^"\']*\1/', 'encoding="UTF-8"', $declaration);
        $fixed = (string) preg_replace('/\bversion\s*=\s*(["\'])1\.1\1/', 'version="1.0"', $fixed);

        return $fixed.substr($text, $end + 2);
    }
}
