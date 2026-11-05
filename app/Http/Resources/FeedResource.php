<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Export\Models\ExportProfile;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feed and its latest import. A URL feed shows its host and the kind of its login only: the full
 * URL often carries an access token, and neither it nor the login ever leaves the server.
 *
 * @property Feed $resource
 */
final class FeedResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'feed';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $feed = $this->resource;
        $options = $feed->parseOptions();
        $latest = $feed->latestImport;
        $run = PipelineRun::query()->where('feed_id', $feed->id)->orderByDesc('id')->first();

        return [
            'id' => $feed->id,
            'name' => $feed->name,
            /** @var 'url'|'upload' */
            'source' => $feed->source,
            'host' => $feed->feedSource?->host,
            /**
             * The kind of login saved for the URL; its values are never returned.
             *
             * @var 'password'|'header'|null
             */
            'auth' => $feed->feedSource?->auth_type,
            /** @var 'active'|'paused' */
            'status' => $feed->status,
            'parse_options' => [
                'encoding' => $options->encoding,
                'delimiter' => $options->delimiter,
                'quoted' => $options->quoted,
                'xml_item_path' => $options->xmlItemPath,
            ],
            'latest_import' => $latest === null ? null : (new ImportSummaryResource($latest))->toArray($request),
            /** The import whose profile is current (the last one read successfully); it stays while a later import fails. */
            'profile_import_id' => $feed->profile_import_id,
            /**
             * The mapping of the current columns: null until the feed has a profile and the mapping
             * was first viewed (that drafts it).
             *
             * @var 'draft'|'approved'|null
             */
            'mapping_status' => $this->mappingStatus($feed),
            /** The latest run through the catalogue and the translation: null before the mapping is first approved. */
            'run' => $run instanceof PipelineRun ? [
                /** @var 'sync'|'plan'|'translate'|'export'|'done' */
                'stage' => $run->stage,
                /** @var 'queued'|'running'|'awaiting_decision'|'held'|'done'|'failed' */
                'status' => $run->status,
                /** Texts this run sends to AI, and how many of them are done. */
                'texts_planned' => (int) ($run->counts['planned'] ?? 0),
                'texts_done' => (int) ($run->counts['texts_done'] ?? 0),
            ] : null,
            /** Paused by the plan's feed limit: no new files or AI work; its feed URLs keep working. */
            'plan_paused' => $feed->plan_paused_at !== null,
            /** How many exports the feed has, and how many of them serve a build. */
            'exports' => [
                'count' => ExportProfile::query()->where('feed_id', $feed->id)->count(),
                'published' => ExportProfile::query()->where('feed_id', $feed->id)->whereNotNull('published_artifact_id')->count(),
            ],
            'created_at' => $feed->created_at?->toIso8601String(),
        ];
    }

    private function mappingStatus(Feed $feed): ?string
    {
        if ($feed->profile_import_id === null) {
            return null;
        }
        $signature = Import::query()->whereKey($feed->profile_import_id)->value('column_signature');
        $status = $signature === null ? null : Mapping::query()->where('feed_id', $feed->id)->where('column_signature', $signature)->value('status');

        return is_string($status) ? $status : null;
    }
}
