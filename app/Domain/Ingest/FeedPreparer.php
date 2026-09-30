<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Ingest\Archive\GzipInflater;
use App\Domain\Ingest\Archive\ZipExtractor;
use App\Domain\Ingest\Encoding\DetectedEncoding;
use App\Domain\Ingest\Encoding\Utf8Normalizer;
use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;

/**
 * Turns a received file into a readable feed: removes up to two layers of zip or gzip, recognises
 * the format and encoding, and writes text formats out as UTF-8. The received file is never
 * modified; everything it produces goes into $workDir.
 */
final readonly class FeedPreparer
{
    private const int MAX_CONTAINERS = 2;

    public function __construct(private IngestLimits $limits) {}

    public static function fromConfig(): self
    {
        return new self(IngestLimits::fromConfig());
    }

    public function prepare(string $received, string $workDir, ParseOptions $options): PreparedFeed
    {
        $size = (int) filesize($received);
        if ($size === 0) {
            throw UnreadableFeed::because(UnreadableReason::Empty, 'The file is empty.');
        }

        if ($size > $this->limits->maxBytes) {
            throw UnreadableFeed::because(UnreadableReason::TooLarge, sprintf('The file is %s; the limit is %s.', $this->megabytes($size), $this->megabytes($this->limits->maxBytes)));
        }

        $warnings = new Warnings;
        $containers = [];
        $innerName = null;
        $current = $received;

        while (($container = Sniffer::container($current)) !== null) {
            if (count($containers) === self::MAX_CONTAINERS) {
                throw UnreadableFeed::because(UnreadableReason::UnsupportedFormat, 'The file is packed in more than two layers of zip or gzip.');
            }

            $unpacked = $workDir.'/unpacked-'.count($containers);
            if ($container === 'gzip') {
                GzipInflater::inflate($current, $unpacked, $this->limits);
            } else {
                $innerName = ZipExtractor::extractFeed($current, $unpacked, $this->limits, $warnings);
            }

            $containers[] = $container;
            $current = $unpacked;
        }

        $sniffed = Sniffer::sniff($current, $options->encoding);
        if (! $sniffed->encoding instanceof DetectedEncoding) {
            return new PreparedFeed($current, $sniffed->format, null, $containers, $innerName, $warnings);
        }

        $normalized = $workDir.'/feed.'.$sniffed->format->extension();
        Utf8Normalizer::normalize($current, $normalized, $sniffed->encoding, $sniffed->format, $warnings);

        return new PreparedFeed($normalized, $sniffed->format, $sniffed->encoding, $containers, $innerName, $warnings);
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 ** 2, 0, '.', ',').' MB';
    }
}
