<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Actions\QueueImport;
use App\Domain\Ingest\Exceptions\FeedBusy;
use App\Domain\Ingest\Models\Import;
use App\Http\Requests\App\RereadImportRequest;
use App\Http\Resources\ImportResource;
use App\Http\Resources\ImportSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

final class FeedImportController
{
    /** A feed's imports, newest first. */
    public function index(Feed $feed): AnonymousResourceCollection
    {
        return ImportSummaryResource::collection(
            Import::query()->where('feed_id', $feed->id)->latest('id')->paginate(20),
        );
    }

    /**
     * Fetch a URL feed now.
     *
     * @throws FeedBusy when an import of this feed is still running (409)
     */
    public function fetch(Feed $feed, QueueImport $queue): JsonResponse
    {
        if ($feed->source !== 'url') {
            throw ValidationException::withMessages(['feed' => 'This feed is fed by uploads; upload a file instead.']);
        }

        return (new ImportSummaryResource($queue->fetch($feed)->refresh()))->response()->setStatusCode(202);
    }

    /** An import in full: how the file was read, its column profile, samples and warnings. */
    public function show(Import $import): ImportResource
    {
        return new ImportResource($import->load('columns'));
    }

    /**
     * Read an import's stored file again with corrected options (encoding, delimiter, quoting,
     * XML item). The options are saved on the feed for its next imports.
     *
     * @throws FeedBusy when an import of this feed is still running (409)
     */
    public function reread(RereadImportRequest $request, Import $import, QueueImport $queue): JsonResponse
    {
        if ($import->blob_path === null) {
            throw ValidationException::withMessages(['import' => 'This import has no stored file to read again.']);
        }

        return (new ImportSummaryResource($queue->reread($import, $request->parseOptions())->refresh()))->response()->setStatusCode(202);
    }
}
