<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Actions;

use App\Domain\Billing\Entitlements;
use App\Domain\Ingest\FeedPreparer;
use App\Domain\Ingest\FeedSizeLimit;
use App\Domain\Ingest\IngestLimits;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\Readers\LimitedRows;
use App\Domain\Ingest\Readers\ReaderFactory;
use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every row of a profiled import, read again from its stored file with the options it was read
 * with, within the workspace's plan limits. The stored file is never read in place: a copy is
 * prepared in a work directory that is removed when the rows are consumed (or abandoned).
 */
final readonly class ReadImportRows
{
    public function __construct(private IngestLimits $limits) {}

    /** @return Generator<int, array<array-key, string>> column name => exact source text */
    public function rows(Import $import): Generator
    {
        $disk = Storage::disk('local');
        if ($import->blob_path === null || ! $disk->exists($import->blob_path)) {
            throw new RuntimeException('The stored file of this import is gone.');
        }

        $work = 'work/rows-'.$import->id.'-'.Str::lower(Str::random(8));
        $disk->makeDirectory($work);
        $workPath = $disk->path($work);

        try {
            $copy = $workPath.'/received';
            copy($disk->path($import->blob_path), $copy);
            $limits = FeedSizeLimit::for(Entitlements::for($import->tenant_id), $this->limits)->limits();
            $options = ParseOptions::fromArray($import->parse_options);
            $prepared = (new FeedPreparer($limits))->prepare($copy, $workPath, $options);
            $reader = ReaderFactory::for($prepared, $options, $limits, $workPath !== '' ? $workPath : null);

            yield from LimitedRows::of($reader, $limits);
        } finally {
            $disk->deleteDirectory($work);
        }
    }
}
