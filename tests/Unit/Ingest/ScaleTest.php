<?php

declare(strict_types=1);

use App\Domain\Ingest\FeedPreparer;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\Profile\ColumnProfiler;
use App\Domain\Ingest\Readers\LimitedRows;
use App\Domain\Ingest\Readers\ReaderFactory;

/*
 * M2's bar: a million rows are read and profiled in bounded memory. The feed is gzipped on the
 * way in, so decompression, conversion, parsing and profiling all run at full scale.
 */
it('reads and profiles a million gzipped rows in under 256 MB', function (): void {
    $source = ingestScratch().'/million.csv.gz';
    $gzip = gzopen($source, 'wb6');
    gzwrite($gzip, "sku;title;price\n");
    for ($i = 0; $i < 1_000_000; $i += 1000) {
        $chunk = '';
        for ($j = $i; $j < $i + 1000; $j++) {
            $chunk .= "SKU-{$j};Produkt Nummer {$j} – Größe M;".($j % 1000).",99\n";
        }
        gzwrite($gzip, $chunk);
    }
    gzclose($gzip);

    $limits = testLimits();
    $work = ingestScratch().'/million-work';
    mkdir($work);
    $baseline = memory_get_usage();

    $feed = (new FeedPreparer($limits))->prepare($source, $work, new ParseOptions);
    $profile = (new ColumnProfiler)->profile(LimitedRows::of(ReaderFactory::for($feed, new ParseOptions, $limits), $limits));

    expect($profile->rowCount)->toBe(1_000_000)
        ->and($profile->sku?->column)->toBe('sku')
        ->and($profile->sku?->confidence)->toBe('high')
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(256 * 1024 * 1024);
})->group('slow');
