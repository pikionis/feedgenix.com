<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Feeds\Models\Feed;
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
            'created_at' => $feed->created_at?->toIso8601String(),
        ];
    }
}
