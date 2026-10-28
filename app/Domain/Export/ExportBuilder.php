<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\Data\ExportColumn;
use App\Domain\Export\Dialects\Dialect;
use App\Domain\Export\Dialects\Dialects;
use App\Domain\Export\Exceptions\ExportBusy;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use App\Domain\Notices\Notify;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds a profile's files and publishes them only when they are whole and right (ADR 0007):
 *
 *  1. write every file into a scratch directory, product by product in keyset chunks; a row whose
 *     golden value the dialect cannot write unchanged goes to the report instead of the file;
 *  2. read every file back through the same dialect: the header, every row's cell count, a SKU in
 *     every row, and exactly the rows written (the legacy check let 1% of the rows go missing);
 *  3. the same content as the published build: nothing changes (no new ETag, no new key);
 *  4. the shrink guard: fewer than 70% of the published rows, when that had more than 50, is not
 *     published unless a person says so (a truncated supplier file);
 *  5. gzip every file and check it by decompressing it: same bytes, same SHA-256 (the legacy app
 *     once served a gzip cut off at row 218,363);
 *  6. store the directory under a new key and move the profile's pointer in one transaction. The
 *     plain file and its gzip are always the same build (the legacy app could serve a new file
 *     with an old .gz).
 *
 * A build that fails at any step leaves the published one serving.
 */
final readonly class ExportBuilder
{
    public function __construct(
        private ColumnPlan $plan,
        private ArtifactServer $server,
    ) {}

    /** @throws ExportBusy when a build of the profile is already running */
    public function build(ExportProfile $profile, bool $allowShrink = false): ExportArtifact
    {
        try {
            $artifact = DB::transaction(static fn (): ExportArtifact => ExportArtifact::query()->forceCreate(['profile_id' => $profile->id, 'status' => 'building']));
        } catch (UniqueConstraintViolationException) {
            throw new ExportBusy;
        }

        $scratch = $this->server->scratch();
        try {
            return $this->run($profile, $artifact, $scratch, $allowShrink);
        } catch (Throwable $failure) {
            $this->server->discard($scratch);
            $artifact->forceFill(['status' => 'failed', 'failure_reason' => 'build_failed', 'failure_message' => mb_substr($failure->getMessage(), 0, 500)])->save();

            throw $failure;
        }
    }

    private function run(ExportProfile $profile, ExportArtifact $artifact, string $scratch, bool $allowShrink): ExportArtifact
    {
        $dialect = Dialects::for($profile->dialect);
        $filled = $this->filled($profile->feed_id);
        $report = new ExportReport;
        $files = [];
        foreach (FeedUrls::files($profile) as $name => $locale) {
            $columns = $this->plan->columns($profile, $filled, $locale);
            $rows = $this->write($scratch.'/'.$name, $profile, $columns, $dialect, $report, $name);
            FileChecks::rows($scratch.'/'.$name, $columns, $dialect, $rows);
            $files[$name] = ['name' => $name, 'locale' => $locale, 'rows' => $rows, 'bytes' => (int) filesize($scratch.'/'.$name), 'sha256' => (string) hash_file('sha256', $scratch.'/'.$name)];
        }
        $rows = $files === [] ? 0 : max(array_column($files, 'rows'));
        $hash = hash('sha256', implode("\n", array_map(static fn (array $file): string => $file['name'].' '.$file['sha256'], $files)));
        $published = ExportArtifact::query()->find($profile->published_artifact_id);

        if ($published instanceof ExportArtifact && $published->content_hash === $hash) {
            $this->server->discard($scratch);
            $artifact->forceFill(['status' => 'unchanged', 'rows' => $rows, 'content_hash' => $hash, 'report' => $report->toArray()])->save();

            return $artifact;
        }

        $keep = (float) config('export.shrink_keep_ratio', 0.7);
        if (! $allowShrink && $published instanceof ExportArtifact && $published->rows > (int) config('export.shrink_min_rows', 50) && $rows < $published->rows * $keep) {
            $this->server->discard($scratch);
            $artifact->forceFill([
                'status' => 'rejected', 'rows' => $rows, 'content_hash' => $hash, 'report' => $report->toArray(),
                'failure_reason' => 'shrink',
                'failure_message' => sprintf('The new build has %s rows, the published one %s: more than %d%% fewer. The published one keeps serving; publish this one yourself if the drop is right.', number_format($rows), number_format($published->rows), (int) round((1 - $keep) * 100)),
            ])->save();
            Notify::send($profile->tenant_id, 'export', "export_rejected:{$artifact->id}", 'action', "{$profile->name}: the new build was held back",
                (string) $artifact->failure_message, 'Open the feed URLs', "/app/feeds/{$profile->feed_id}/publish", email: true);

            return $artifact;
        }

        foreach ($files as $name => $file) {
            $files[$name] = [...$file, ...FileChecks::gzip($scratch.'/'.$name, $file['bytes'], $file['sha256'])];
        }
        // A random part too: files a restored database no longer knows about can never take a build's place.
        $key = "{$profile->tenant_id}/{$profile->id}/{$artifact->id}-".Str::lower(Str::random(10));
        $this->server->store($scratch, $key);

        DB::transaction(static function () use ($profile, $artifact, $key, $files, $rows, $hash, $report): void {
            $locked = ExportProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            ExportArtifact::query()->where('profile_id', $profile->id)->where('status', 'published')->update(['status' => 'superseded']);
            $artifact->forceFill([
                'status' => 'published', 'storage_key' => $key, 'files' => array_values($files), 'rows' => $rows,
                'content_hash' => $hash, 'report' => $report->toArray(), 'published_at' => now(),
            ])->save();
            $locked->forceFill(['published_artifact_id' => $artifact->id])->save();
        });
        $profile->published_artifact_id = $artifact->id;
        $this->prune($profile);

        return $artifact;
    }

    /** @return list<string> the fields the feed's approved mapping fills */
    private function filled(int $feedId): array
    {
        $mapping = Mapping::query()->where('feed_id', $feedId)->where('status', 'approved')->latest('approved_at')->first();
        if (! $mapping instanceof Mapping) {
            return ['sku'];
        }

        return array_values(array_unique(['sku', ...MappingField::query()->where('mapping_id', $mapping->id)->where('strategy', '<>', 'none')->pluck('target')->all()]));
    }

    /**
     * @param  list<ExportColumn>  $columns
     * @return int rows written
     */
    private function write(string $path, ExportProfile $profile, array $columns, Dialect $dialect, ExportReport $report, string $file): int
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Could not create [{$file}].");
        }
        $locales = array_values(array_unique(array_filter(array_map(static fn (ExportColumn $column): ?string => $column->locale, $columns))));
        $rows = 0;
        try {
            $header = array_map(static fn (ExportColumn $column): string => $dialect->cell($column->header, true) ?? throw new RuntimeException("The header [{$column->header}] cannot be written as a {$dialect->key()} cell."), $columns);
            $this->put($handle, $dialect->line($header));

            Product::query()->where('feed_id', $profile->feed_id)->where('status', 'active')->select(['id', 'sku', 'source_values'])
                ->chunkById((int) config('export.chunk', 500), function ($products) use ($handle, $columns, $dialect, $report, $file, $locales, &$rows): void {
                    $texts = $this->texts($products->modelKeys(), $locales);
                    foreach ($products as $product) {
                        /** @var Product $product */
                        $cells = $this->cells($product, $texts[$product->id] ?? [], $columns, $dialect, $report, $file);
                        if ($cells !== null) {
                            $this->put($handle, $dialect->line($cells));
                            $rows++;
                        }
                    }
                });
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, string>>  $texts  field => locale => publishable text
     * @param  list<ExportColumn>  $columns
     * @return list<string>|null null when the row cannot be written as it is
     */
    private function cells(Product $product, array $texts, array $columns, Dialect $dialect, ExportReport $report, string $file): ?array
    {
        $values = $product->source_values;
        $cells = [];
        foreach ($columns as $column) {
            $value = $column->locale === null ? ($column->field === 'sku' ? $product->sku : (string) ($values[$column->field] ?? '')) : ($texts[$column->field][$column->locale] ?? '');
            if ($column->transform !== null) {
                $value = Transforms::apply($column->transform, $value, $values);
            }
            if ($value === '' && $column->required) {
                $report->missing($file, $column->header);
            }
            $cell = $dialect->cell($value, $column->exact);
            if ($cell === null) {
                $report->leftOut($file, $product->sku, $column->header, 'golden_unwritable');

                return null;
            }
            $cells[] = $cell;
        }

        return $cells;
    }

    /**
     * The texts a file may publish: the feed's own, and translations that are ready (never stale,
     * failed or waiting ones: empty rather than wrong).
     *
     * @param  array<int, mixed>  $productIds
     * @param  list<string>  $locales
     * @return array<int, array<string, array<string, string>>> product => field => locale => text
     */
    private function texts(array $productIds, array $locales): array
    {
        if ($locales === []) {
            return [];
        }
        $texts = [];
        $rows = ProductText::query()->whereIn('product_id', $productIds)->whereIn('locale', $locales)
            ->where(static fn (Builder $publishable) => $publishable->where('role', 'source')->orWhere(static fn (Builder $ready) => $ready->where('role', 'target')->where('status', 'ready')))
            ->get(['product_id', 'field', 'locale', 'value']);
        foreach ($rows as $text) {
            if ($text->value !== null) {
                $texts[$text->product_id][$text->field][$text->locale] = $text->value;
            }
        }

        return $texts;
    }

    /** The published build and the newest others keep their files (to go back to one); every record stays. */
    private function prune(ExportProfile $profile): void
    {
        $old = ExportArtifact::query()->where('profile_id', $profile->id)->where('status', 'superseded')->where('files_deleted', false)
            ->orderByDesc('id')->skip(max(0, (int) config('export.keep_builds', 3) - 1))->take(100)->get();
        foreach ($old as $artifact) {
            if ($artifact->storage_key !== null) {
                $this->server->delete($artifact->storage_key);
            }
            $artifact->forceFill(['files_deleted' => true])->save();
        }
    }

    /** @param resource $handle */
    private function put($handle, string $line): void
    {
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException('The file could not be written (disk full?).');
        }
    }
}
