<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Archive;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\IngestLimits;
use ErrorException;
use InflateContext;
use RuntimeException;

/**
 * Streams a gzip file out to disk, one megabyte at a time.
 *
 * Integrity comes from zlib itself: it checks every member's CRC-32 and length, and a truncated
 * stream never reaches its end. Concatenated members are read in turn. Output beyond the inflate
 * cap, or at a ratio no real feed has, is a decompression bomb.
 *
 * Legacy defect D1: gz feeds were never decompressed. The legacy lesson: a silently truncated
 * gzip was served as if it were complete.
 */
final class GzipInflater
{
    private const CHUNK = 1 << 20;

    /** @return int the bytes written */
    public static function inflate(string $source, string $destination, IngestLimits $limits): int
    {
        $compressed = (int) filesize($source);
        $in = fopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the gzip file.');
        }

        try {
            $context = self::newContext();
            $written = 0;
            $pending = '';
            $finished = false;

            while (true) {
                if ($pending === '') {
                    $read = fread($in, self::CHUNK);
                    if ($read === false || $read === '') {
                        break;
                    }
                    $pending = $read;
                }

                $before = inflate_get_read_len($context);
                $output = self::add($context, $pending);
                $consumed = inflate_get_read_len($context) - $before;

                if ($output !== '') {
                    $written += strlen($output);
                    self::guard($written, $compressed, $limits);
                    fwrite($out, $output);
                }

                if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                    $pending = '';
                    $finished = false;

                    continue;
                }

                $finished = true;
                $pending = substr($pending, $consumed);
                $pending = self::nextMember($pending, $in);
                if ($pending === null) {
                    break;
                }

                $context = self::newContext();
                $finished = false;
            }

            if (! $finished) {
                throw UnreadableFeed::because(UnreadableReason::TruncatedArchive, 'The gzip file ends before its data does: the upload or download was cut off. Try again.');
            }

            if ($written === 0) {
                throw UnreadableFeed::because(UnreadableReason::Empty, 'The gzip file contains nothing.');
            }

            return $written;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * After a member ends: the bytes that start the next member, or null when only padding (or
     * nothing) follows.
     *
     * @param  resource  $in
     */
    private static function nextMember(string $rest, $in): ?string
    {
        while (strlen($rest) < 2) {
            $read = fread($in, self::CHUNK);
            if ($read === false || $read === '') {
                break;
            }
            $rest .= $read;
        }

        if (str_starts_with($rest, "\x1F\x8B")) {
            return $rest;
        }

        // Trailing zeros are tape-style padding; anything else is garbage after a complete stream.
        if (trim($rest, "\0") !== '') {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The gzip file has unexpected data after its end.');
        }

        return null;
    }

    private static function guard(int $written, int $compressed, IngestLimits $limits): void
    {
        if ($written > $limits->maxInflatedBytes
            || ($written > $limits->ratioAfterBytes && $written > $compressed * $limits->maxInflateRatio)) {
            throw UnreadableFeed::because(UnreadableReason::DecompressionBomb, 'The gzip file expands far beyond any real feed, so it was not read.');
        }
    }

    private static function newContext(): InflateContext
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new RuntimeException('zlib is not available.');
        }

        return $context;
    }

    /** zlib reports damaged data as a PHP warning; it becomes a typed refusal here. */
    private static function add(InflateContext $context, string $data): string
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $output = inflate_add($context, $data, ZLIB_SYNC_FLUSH);
        } catch (ErrorException) {
            $output = false;
        } finally {
            restore_error_handler();
        }

        if ($output === false) {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The gzip file is damaged (its data does not match its checksum).');
        }

        return $output;
    }
}
