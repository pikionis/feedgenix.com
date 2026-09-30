<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Actions\Uploads;
use App\Domain\Ingest\Exceptions\FeedBusy;
use App\Domain\Ingest\Models\UploadSession;
use App\Http\Requests\App\CompleteUploadRequest;
use App\Http\Requests\App\StartUploadRequest;
use App\Http\Resources\ImportSummaryResource;
use App\Http\Resources\UploadSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class UploadController
{
    /** Start a chunked upload of a feed file (up to the size cap). */
    public function start(StartUploadRequest $request, Feed $feed, Uploads $uploads): JsonResponse
    {
        $session = $uploads->start($feed, (string) $request->validated('filename'), (int) $request->validated('size'));

        return (new UploadSessionResource($session))->response()->setStatusCode(201);
    }

    /**
     * Send one chunk as the raw request body (`application/octet-stream`). Chunks may arrive in any
     * order and be sent again; each must have exactly its expected length.
     */
    public function chunk(Request $request, Feed $feed, UploadSession $upload, int $index, Uploads $uploads): Response
    {
        $body = $request->getContent(true);
        if (! is_resource($body)) {
            throw new RuntimeException('The request body could not be read.');
        }

        $uploads->store($upload, $index, $body);

        return response()->noContent();
    }

    /**
     * Complete the upload: the chunks are assembled, checked and imported.
     *
     * @throws FeedBusy when an import of this feed is still running (409)
     */
    public function complete(CompleteUploadRequest $request, Feed $feed, UploadSession $upload, Uploads $uploads): JsonResponse
    {
        $sha256 = $request->validated('sha256');
        $import = $uploads->complete($upload, is_string($sha256) ? $sha256 : null);

        return (new ImportSummaryResource($import->refresh()))->response()->setStatusCode(202);
    }
}
