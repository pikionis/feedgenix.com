<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Encoding\DetectedEncoding;
use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Fetch\Fetcher;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\ImportColumn;
use App\Domain\Ingest\Profile\ColumnProfile;
use App\Domain\Ingest\Profile\ColumnProfiler;
use App\Domain\Ingest\Profile\SkuSuggestion;
use App\Domain\Ingest\Readers\CsvRowReader;
use App\Domain\Ingest\Readers\LimitedRows;
use App\Domain\Ingest\Readers\ReaderFactory;
use App\Domain\Ingest\Readers\XmlRowReader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs one import: receive the file (download or upload), keep it once per content, prepare and
 * read it, profile it, and record the outcome. Either the import ends profiled, unchanged or
 * failed with a reason; it never stays half done.
 */
final readonly class ImportRunner
{
    public function __construct(
        private Fetcher $fetcher,
        private IngestLimits $limits,
        private ColumnProfiler $profiler,
    ) {}

    public function run(Import $import): void
    {
        $import->forceFill([
            'status' => $import->origin === 'fetch' ? ImportStatus::Fetching : ImportStatus::Reading,
            'started_at' => now(),
        ])->save();

        $disk = Storage::disk('local');
        $work = 'work/'.$import->id.'-'.Str::lower(Str::random(8));
        $disk->makeDirectory($work);
        $workPath = $disk->path($work);
        $conditional = null;

        try {
            $previous = $this->currentProfile($import);
            [$received, $conditional] = $this->receive($import, $workPath, $previous);
            if ($received === null) {
                $this->finish($import, ImportStatus::Unchanged, ['reuses_import_id' => $previous?->id]);
                $this->remember($import, $conditional);

                return;
            }

            $sha = (string) hash_file('sha256', $received);
            if ($import->origin !== 'reread' && $previous instanceof Import
                && $previous->content_sha256 === $sha && $previous->parse_options == $import->parse_options) {
                $this->discard($disk, $import, $received);
                $this->finish($import, ImportStatus::Unchanged, ['reuses_import_id' => $previous->id, 'content_sha256' => $sha]);
                $this->remember($import, $conditional);

                return;
            }

            $blob = $this->keep($disk, $import, $received, $sha);
            $import->forceFill([
                'status' => ImportStatus::Reading,
                'content_sha256' => $sha,
                'blob_path' => $blob,
                'received_bytes' => $disk->size($blob),
                'staging_path' => null,
            ])->save();

            $this->read($import, $disk->path($blob), $workPath);
            $this->remember($import, $conditional);
        } catch (UnreadableFeed $refusal) {
            $this->fail($import, $refusal->reason->value, $refusal->getMessage());
        } catch (FetchFailed $failure) {
            $this->fail($import, $failure->reason->value, $failure->getMessage(), $failure->retryAfterSeconds);
        } finally {
            $disk->deleteDirectory($work);
            if ($import->staging_path !== null && $import->status === ImportStatus::Failed) {
                $disk->delete($import->staging_path);
            }
        }
    }

    /**
     * The file to read: downloaded, uploaded, or the stored one for a re-read. Null when the
     * server says the feed has not changed since the current profile.
     *
     * @return array{0: string|null, 1: array{etag: ?string, last_modified: ?string}|null}
     */
    private function receive(Import $import, string $workPath, ?Import $previous): array
    {
        $disk = Storage::disk('local');

        if ($import->origin === 'upload') {
            return [$disk->path((string) $import->staging_path), null];
        }

        if ($import->origin === 'reread') {
            $source = $import->reuses_import_id === null ? null : Import::query()->find($import->reuses_import_id);
            if ($source?->blob_path === null || ! $disk->exists($source->blob_path)) {
                throw new RuntimeException('The stored file to re-read is gone.');
            }

            // A copy: the stored file is never read in place by a job that could move it.
            $copy = $workPath.'/received';
            copy($disk->path($source->blob_path), $copy);

            return [$copy, null];
        }

        $source = FeedSource::query()->where('feed_id', $import->feed_id)->firstOrFail();
        $conditional = $previous instanceof Import;
        $result = $this->fetcher->fetch(
            $source->url,
            $workPath.'/received',
            $conditional ? $source->etag : null,
            $conditional ? $source->last_modified : null,
        );

        $validators = ['etag' => $result->etag, 'last_modified' => $result->lastModified];
        if ($result->notModified) {
            return [null, null];
        }

        return [$workPath.'/received', $validators];
    }

    private function read(Import $import, string $path, string $workPath): void
    {
        $options = ParseOptions::fromArray($import->parse_options);
        $prepared = (new FeedPreparer($this->limits))->prepare($path, $workPath, $options);
        $reader = ReaderFactory::for($prepared, $options, $this->limits, $workPath !== '' ? $workPath : null);
        $profile = $this->profiler->profile(LimitedRows::of($reader, $this->limits));

        $warnings = new Warnings;
        $warnings->merge($prepared->warnings);
        $warnings->merge($reader->warnings());
        if ($profile->sku?->confidence === 'low') {
            $warnings->add('sku_uncertain', "“{$profile->sku->column}” looks like the product ID, but {$profile->sku->empty} rows have none and {$profile->sku->duplicates} values repeat. Confirm it in the mapping.");
        }

        DB::transaction(function () use ($import, $prepared, $reader, $profile, $warnings): void {
            $import->columns()->delete();
            ImportColumn::query()->insert(array_map(static fn (ColumnProfile $column): array => [
                'tenant_id' => $import->tenant_id,
                'import_id' => $import->id,
                'position' => $column->position,
                'name' => $column->name,
                'filled' => $column->filled,
                'fill_rate' => $column->fillRate,
                'max_length' => $column->maxLength,
                'kind' => $column->kind->value,
                'examples' => json_encode($column->examples, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ], $profile->columns));

            $this->finish($import, ImportStatus::Profiled, [
                'format' => $prepared->format->value,
                'containers' => $prepared->containers,
                'inner_name' => $prepared->innerName,
                'encoding' => $prepared->encoding instanceof DetectedEncoding ? ['name' => $prepared->encoding->name, 'reason' => $prepared->encoding->reason] : null,
                'delimiter' => $reader instanceof CsvRowReader ? $reader->delimiter() : null,
                'xml_item_path' => $reader instanceof XmlRowReader ? $reader->itemPath() : null,
                'row_count' => $profile->rowCount,
                'column_count' => count($profile->columns),
                'column_signature' => $profile->columnSignature(),
                'sku' => $profile->sku instanceof SkuSuggestion ? [
                    'column' => $profile->sku->column,
                    'confidence' => $profile->sku->confidence,
                    'empty' => $profile->sku->empty,
                    'duplicates' => $profile->sku->duplicates,
                ] : null,
                'sample_rows' => $profile->sampleRows,
                'warnings' => $warnings->all(),
            ]);
        });
    }

    /** The received bytes, kept once per workspace and content (imports/{tenant}/{ab}/{sha256}). */
    private function keep(Filesystem $disk, Import $import, string $received, string $sha): string
    {
        $blob = "imports/{$import->tenant_id}/".substr($sha, 0, 2)."/{$sha}";
        if ($disk->exists($blob)) {
            $this->discard($disk, $import, $received);

            return $blob;
        }

        $disk->makeDirectory(dirname($blob));
        if (! rename($received, $disk->path($blob))) {
            throw new RuntimeException('Cannot store the received feed.');
        }

        return $blob;
    }

    private function discard(Filesystem $disk, Import $import, string $received): void
    {
        if ($import->origin === 'upload' && $import->staging_path !== null) {
            $disk->delete($import->staging_path);

            return;
        }

        if (is_file($received)) {
            unlink($received);
        }
    }

    /** The feed's current profile: its last successfully read import (not this one). */
    private function currentProfile(Import $import): ?Import
    {
        return Import::query()
            ->where('feed_id', $import->feed_id)
            ->where('status', ImportStatus::Profiled)
            ->whereKeyNot($import->id)
            ->latest('id')
            ->first();
    }

    /**
     * The ETag and Last-Modified of a successful fetch, for the next conditional request. Written
     * only once the import succeeded: a broken download must never be "not modified" next time.
     *
     * @param  array{etag: ?string, last_modified: ?string}|null  $validators
     */
    private function remember(Import $import, ?array $validators): void
    {
        if ($validators === null || $import->origin !== 'fetch') {
            return;
        }

        FeedSource::query()->where('feed_id', $import->feed_id)->update($validators);
    }

    /** @param array<string, mixed> $attributes */
    private function finish(Import $import, ImportStatus $status, array $attributes = []): void
    {
        $import->forceFill([...$attributes, 'status' => $status, 'finished_at' => now()])->save();
    }

    private function fail(Import $import, string $reason, string $message, ?int $retryAfter = null): void
    {
        $this->finish($import, ImportStatus::Failed, [
            'failure_reason' => $reason,
            'failure_message' => $message,
            'retry_after_seconds' => $retryAfter,
        ]);
    }
}
