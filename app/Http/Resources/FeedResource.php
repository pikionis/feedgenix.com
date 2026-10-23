<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feed and its latest import. A URL feed shows its host only: the full URL often carries an
 * access token and never leaves the server.
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

        return [
            'id' => $feed->id,
            'name' => $feed->name,
            /** @var 'url'|'upload' */
            'source' => $feed->source,
            'host' => $feed->feedSource?->host,
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
