<?php

declare(strict_types=1);

use App\Domain\Ingest\FeedPreparer;
use App\Domain\Ingest\Fetch\Fetcher;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\HttpFetcher;
use App\Domain\Ingest\IngestLimits;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\PreparedFeed;
use App\Domain\Ingest\Readers\LimitedRows;
use App\Domain\Ingest\Readers\ReaderFactory;
use App\Domain\Ingest\Warnings;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\FakeFetchNetwork;

/*
 * Synthetic feed fixtures for the ingest tests: files are written per test into a scratch directory
 * that is removed after each test. No customer data, ever.
 */

function ingestScratch(): string
{
    static $dir = null;
    $dir ??= storage_path('framework/testing/ingest-'.getmypid());
    File::ensureDirectoryExists($dir);

    return $dir;
}

function cleanIngestScratch(): void
{
    File::deleteDirectory(ingestScratch());
}

/** Writes $bytes to a new file and returns its path. */
function feedFile(string $bytes, string $name = 'feed'): string
{
    $path = ingestScratch().'/'.uniqid('', true).'-'.$name;
    file_put_contents($path, $bytes);

    return $path;
}

function testLimits(array $overrides = []): IngestLimits
{
    return IngestLimits::fromConfig()->with($overrides);
}

/**
 * Prepares and reads a feed the way an import does.
 *
 * @return array{feed: PreparedFeed, rows: list<array<array-key, string>>, warnings: Warnings}
 */
function readFeed(string $path, ?ParseOptions $options = null, ?IngestLimits $limits = null): array
{
    $options ??= new ParseOptions;
    $limits ??= testLimits();
    $work = ingestScratch().'/work-'.uniqid('', true);
    File::ensureDirectoryExists($work);

    $feed = (new FeedPreparer($limits))->prepare($path, $work, $options);
    $reader = ReaderFactory::for($feed, $options, $limits, $work);
    $rows = iterator_to_array(LimitedRows::of($reader, $limits), false);

    $warnings = new Warnings;
    $warnings->merge($feed->warnings);
    $warnings->merge($reader->warnings());

    return ['feed' => $feed, 'rows' => $rows, 'warnings' => $warnings];
}

/** @return list<array<array-key, string>> */
function readRows(string $bytes, ?ParseOptions $options = null): array
{
    return readFeed(feedFile($bytes), $options)['rows'];
}

/** @param list<list<mixed>> $rows */
function xlsxFile(array $rows, ?callable $configure = null): string
{
    $path = ingestScratch().'/'.uniqid('', true).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    if ($configure !== null) {
        $configure($writer);
    }
    // Dates get a date format, as in any real workbook (without one Excel stores a plain number).
    $dateStyle = (new Style)->withFormat('yyyy-mm-dd hh:mm:ss');
    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(
            static fn (mixed $value): Cell => Cell::fromValue($value, $value instanceof DateTimeInterface ? $dateStyle : null),
            $row,
        )));
    }
    $writer->close();

    return $path;
}

/** A scripted network in place of the real one for every fetch the app makes. */
function network(): FakeFetchNetwork
{
    $network = new FakeFetchNetwork;
    app()->instance(Fetcher::class, new HttpFetcher(
        $network,
        $network,
        new FetchSettings(maxBytes: (int) config('ingest.limits.max_bytes')),
    ));

    return $network;
}

/** Creates a feed through the API as $user; returns the response. */
function createFeed(User $user, array $body): TestResponse
{
    return test()->actingAs($user)->postJson('/api/v1/feeds', $body);
}

/**
 * Uploads $bytes to $feedId through the chunked API as $user, in $chunkSize pieces sent in
 * reverse order (the order must not matter), and completes it.
 */
function uploadFeed(User $user, int $feedId, string $bytes, string $filename = 'feed.csv'): TestResponse
{
    $start = test()->actingAs($user)->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => $filename, 'size' => strlen($bytes)])->assertCreated();
    $id = (string) $start->json('upload.id');
    $chunk = (int) $start->json('upload.chunk_size');

    for ($index = (int) $start->json('upload.total_chunks') - 1; $index >= 0; $index--) {
        test()->actingAs($user)->call('PUT', "/api/v1/feeds/{$feedId}/uploads/{$id}/chunks/{$index}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $index * $chunk, $chunk))
            ->assertNoContent();
    }

    return test()->actingAs($user)->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete", ['sha256' => hash('sha256', $bytes)]);
}

/** @param array<string, string> $entries name => bytes */
function zipFile(array $entries, ?callable $configure = null): string
{
    $path = ingestScratch().'/'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    if ($configure !== null) {
        $configure($zip);
    }
    $zip->close();

    return $path;
}
