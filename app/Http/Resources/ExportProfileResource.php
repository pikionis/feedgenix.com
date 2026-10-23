<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Export\ColumnPlan;
use App\Domain\Export\FeedUrls;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Export\Models\FeedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An export profile with its feed URLs, the build it serves, the latest attempt and the builds a
 * person can go back to.
 *
 * @property ExportProfile $resource
 */
final class ExportProfileResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'export';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;
        $token = FeedUrls::current($profile);
        $published = ExportArtifact::query()->find($profile->published_artifact_id);
        $latest = ExportArtifact::query()->where('profile_id', $profile->id)->latest('id')->first();
        $grace = FeedToken::query()->where('profile_id', $profile->id)->whereNotNull('expires_at')->where('expires_at', '>', now())->max('expires_at');

        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'preset' => $profile->preset,
            'preset_label' => ColumnPlan::preset($profile->preset)['label'],
            /** @var 'csv'|'tsv' */
            'dialect' => $profile->dialect,
            /** @var 'wide'|'per_language' */
            'layout' => $profile->layout,
            /** @var list<string> */
            'locales' => $profile->locales,
            /** @var 'active'|'paused' */
            'status' => $profile->status,
            /** Send it back with a change: a save on an older version is refused (409). */
            'version' => $profile->version,
            /**
             * The public URLs, one per file; the .gz form serves the same build compressed.
             *
             * @var list<array{file: string, locale: string|null, url: string, gz_url: string}>
             */
            'urls' => $token === null ? [] : collect(FeedUrls::files($profile))->map(static fn (?string $locale, string $file): array => [
                'file' => $file, 'locale' => $locale, 'url' => FeedUrls::url($token, $file), 'gz_url' => FeedUrls::url($token, $file.'.gz'),
            ])->values()->all(),
            /**
             * Until then the URLs before the last new token still work.
             *
             * @var string|null
             */
            'previous_urls_until' => is_string($grace) ? $grace : null,
            /**
             * The build the URLs serve.
             *
             * @var array{id: int, status: 'building'|'published'|'superseded'|'unchanged'|'rejected'|'failed', rows: int, files: list<array{name: string, locale: string|null, rows: int, bytes: int, gz_bytes: int}>, report: array{left_out: int, missing: array<string, array<string, int>>, examples: list<array{file: string, sku: string, column: string, reason: string}>}, failure_reason: string|null, failure_message: string|null, created_at: string|null, published_at: string|null}|null
             */
            'published' => $published instanceof ExportArtifact ? $this->artifact($published) : null,
            /**
             * The latest attempt, published or not (rejected by the shrink guard, failed, building).
             *
             * @var array{id: int, status: 'building'|'published'|'superseded'|'unchanged'|'rejected'|'failed', rows: int, files: list<array{name: string, locale: string|null, rows: int, bytes: int, gz_bytes: int}>, report: array{left_out: int, missing: array<string, array<string, int>>, examples: list<array{file: string, sku: string, column: string, reason: string}>}, failure_reason: string|null, failure_message: string|null, created_at: string|null, published_at: string|null}|null
             */
            'latest' => $latest instanceof ExportArtifact ? $this->artifact($latest) : null,
            /**
             * Earlier builds whose files are kept: publishing one serves it again at once.
             *
             * @var list<array{id: int, rows: int, published_at: string|null}>
             */
            'earlier' => ExportArtifact::query()->where('profile_id', $profile->id)->where('status', 'superseded')->where('files_deleted', false)
                ->orderByDesc('id')->get(['id', 'rows', 'published_at'])
                ->map(static fn (ExportArtifact $artifact): array => ['id' => $artifact->id, 'rows' => $artifact->rows, 'published_at' => $artifact->published_at?->toIso8601String()])->all(),
        ];
    }

    /**
     * @return array{id: int, status: 'building'|'published'|'superseded'|'unchanged'|'rejected'|'failed', rows: int, files: list<array{name: string, locale: string|null, rows: int, bytes: int, gz_bytes: int}>, report: array{left_out: int, missing: array<string, array<string, int>>, examples: list<array{file: string, sku: string, column: string, reason: string}>}, failure_reason: string|null, failure_message: string|null, created_at: string|null, published_at: string|null}
     */
    private function artifact(ExportArtifact $artifact): array
    {
        $report = $artifact->report;

        /** @var 'building'|'published'|'superseded'|'unchanged'|'rejected'|'failed' $status */
        $status = $artifact->status;

        return [
            'id' => $artifact->id,
            'status' => $status,
            'rows' => $artifact->rows,
            'files' => array_map(static fn (array $file): array => ['name' => $file['name'], 'locale' => $file['locale'], 'rows' => $file['rows'], 'bytes' => $file['bytes'], 'gz_bytes' => $file['gz_bytes']], $artifact->files),
            'report' => [
                'left_out' => (int) ($report['left_out'] ?? 0),
                'missing' => is_array($report['missing'] ?? null) ? $report['missing'] : [],
                'examples' => is_array($report['examples'] ?? null) ? array_values($report['examples']) : [],
            ],
            'failure_reason' => $artifact->failure_reason,
            'failure_message' => $artifact->failure_message,
            'created_at' => $artifact->created_at?->toIso8601String(),
            'published_at' => $artifact->published_at?->toIso8601String(),
        ];
    }
}
