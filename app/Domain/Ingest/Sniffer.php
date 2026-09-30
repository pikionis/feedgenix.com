<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Ingest\Archive\ZipExtractor;
use App\Domain\Ingest\Encoding\DetectedEncoding;
use App\Domain\Ingest\Encoding\EncodingDetector;
use App\Domain\Ingest\Encoding\Encodings;
use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use UConverter;

/**
 * Recognises a file by its content, never by its name: a URL called feed.csv may return a gzip,
 * a login page or an old Excel file.
 */
final class Sniffer
{
    private const HEAD = 1 << 20;

    /** 'gzip' or 'zip' when the file is a container, else null. */
    public static function container(string $path): ?string
    {
        $magic = self::read($path, 8);

        return match (true) {
            str_starts_with($magic, "\x1F\x8B") => 'gzip',
            (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06")) && ! ZipExtractor::isSpreadsheet($path) => 'zip',
            default => null,
        };
    }

    public static function sniff(string $path, ?string $encodingOverride = null): Sniffed
    {
        $size = (int) filesize($path);
        $head = self::read($path, self::HEAD);
        if ($size === 0 || trim($head, " \t\r\n\0\xEF\xBB\xBF") === '') {
            throw UnreadableFeed::because(UnreadableReason::Empty, 'The file is empty.');
        }

        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw UnreadableFeed::because(UnreadableReason::LegacyExcel, 'This is an old Excel file (.xls). Save it as .xlsx or CSV and upload it again.');
        }

        if (str_starts_with($head, "PK\x03\x04") && ZipExtractor::isSpreadsheet($path)) {
            return new Sniffed(Format::Xlsx, null);
        }

        if (str_starts_with($head, '%PDF')) {
            throw UnreadableFeed::because(UnreadableReason::UnsupportedFormat, 'This is a PDF, not a product feed. Use a CSV, XML, JSON or Excel file.');
        }

        $encoding = EncodingDetector::detect($head, $size <= self::HEAD);
        $text = self::decodeHead($head, $encoding);
        $format = self::format($text);

        if ($format === Format::Xml && $encoding->bomBytes === 0) {
            $encoding = self::xmlDeclared($text, $encoding) ?? $encoding;
        }

        if ($encodingOverride !== null) {
            $encoding = $encoding->withOverride($encodingOverride);
        }

        if (self::looksBinary($text)) {
            throw UnreadableFeed::because(UnreadableReason::UnsupportedFormat, 'This is not a CSV, XML, JSON or Excel file.');
        }

        return new Sniffed($format, $encoding);
    }

    private static function format(string $text): Format
    {
        $start = ltrim($text, " \t\r\n");
        $lower = strtolower(substr($start, 0, 256));

        if (str_starts_with($lower, '<!doctype html') || str_starts_with($lower, '<html')) {
            throw UnreadableFeed::because(UnreadableReason::UnsupportedFormat, 'This is a web page, not a feed. If it came from a URL, the address may need a login or point to a page instead of the file.');
        }

        if (str_starts_with($start, '<')) {
            return Format::Xml;
        }

        if (str_starts_with($start, '[')) {
            return Format::Json;
        }

        if (str_starts_with($start, '{')) {
            // One object per line: the first line is a whole object and the next non-empty line starts another.
            $lines = array_values(array_filter(
                array_map(trim(...), preg_split('/\R/', $start, 20) ?: []),
                static fn (string $line): bool => $line !== '',
            ));
            $first = json_decode($lines[0] ?? '', false);

            return is_object($first) && str_starts_with($lines[1] ?? '', '{') ? Format::NdJson : Format::Json;
        }

        return Format::Csv;
    }

    /** XML without a BOM is in the encoding its declaration names (UTF-8 when there is none). */
    private static function xmlDeclared(string $text, DetectedEncoding $detected): ?DetectedEncoding
    {
        if (preg_match('/^\s*<\?xml[^>]*\bencoding\s*=\s*["\']([A-Za-z0-9._:-]+)["\']/', $text, $match) !== 1) {
            return null;
        }

        $declared = Encodings::canonical($match[1]);
        if ($declared === null) {
            throw UnreadableFeed::because(UnreadableReason::UnknownEncoding, "The XML says it is in {$match[1]}, which FeedGenix cannot convert. Save it as UTF-8.");
        }

        // A declared UTF-8 that is not valid UTF-8 keeps the detected fallback.
        if ($declared === Encodings::UTF8) {
            return null;
        }

        return new DetectedEncoding($declared, 'xml_declaration', $detected->bomBytes);
    }

    private static function decodeHead(string $head, DetectedEncoding $encoding): string
    {
        $bytes = substr($head, $encoding->bomBytes, 8192);
        $unit = Encodings::unitBytes($encoding->name);
        $bytes = substr($bytes, 0, strlen($bytes) - (strlen($bytes) % $unit));

        return (string) UConverter::transcode($bytes, 'UTF-8', Encodings::converter($encoding->name));
    }

    /**
     * More than one character in twenty a control character (C0 other than tab and line breaks,
     * DEL, or C1): not text. Real feeds have next to none; random bytes read as Windows-1252 have
     * about one in eight.
     */
    private static function looksBinary(string $text): bool
    {
        $sample = mb_substr($text, 0, 4096);
        $characters = mb_strlen($sample);
        if ($characters === 0) {
            return false;
        }

        $controls = (int) preg_match_all('/[\x{00}-\x{08}\x{0E}-\x{1F}\x{7F}-\x{9F}]/u', $sample);

        return $controls > $characters / 20;
    }

    /** @param int<1, max> $bytes */
    private static function read(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            return (string) fread($handle, $bytes);
        } finally {
            fclose($handle);
        }
    }
}
