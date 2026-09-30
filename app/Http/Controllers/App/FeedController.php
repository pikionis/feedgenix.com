<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Feeds\Actions\CreateFeed;
use App\Domain\Feeds\Actions\DeleteFeed;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Actions\QueueImport;
use App\Domain\Ingest\Fetch\UrlGuard;
use App\Domain\Ingest\ParseOptions;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Requests\App\StoreFeedRequest;
use App\Http\Requests\App\UpdateFeedRequest;
use App\Http\Resources\FeedResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class FeedController
{
    use ActsInWorkspace;

    /** The workspace's feeds, each with its latest import. */
    public function index(): AnonymousResourceCollection
    {
        $feeds = Feed::query()->with(['feedSource', 'latestImport'])->orderBy('id')->get();

        return FeedResource::collection($feeds);
    }

    /**
     * Create a feed (owner or admin).
     *
     * With a URL the first download starts at once; without one the feed waits for an upload. The
     * plan's feed limit applies: over it, a 402 names the plan that fits.
     *
     * @throws PlanLimitExceeded
     */
    public function store(StoreFeedRequest $request, CreateFeed $create, QueueImport $queue): JsonResponse
    {
        $this->authorizeManage($request);

        $feed = $create->handle($this->workspace(), $request->name(), $request->url(), $request->parseOptions());
        if ($feed->source === 'url') {
            $queue->fetch($feed);
        }

        return (new FeedResource($feed->load(['feedSource', 'latestImport'])))->response()->setStatusCode(201);
    }

    /** A feed with its latest import. */
    public function show(Feed $feed): FeedResource
    {
        return new FeedResource($feed->load(['feedSource', 'latestImport']));
    }

    /**
     * Rename a feed, change its URL or its reading options (owner or admin). What is not sent is
     * kept: an omitted URL never clears the stored one.
     */
    public function update(UpdateFeedRequest $request, Feed $feed): FeedResource
    {
        $this->authorizeManage($request);

        if ($request->has('name')) {
            $feed->fill(['name' => trim((string) $request->validated('name'))]);
        }

        $options = $request->parseOptions();
        if ($options instanceof ParseOptions) {
            $feed->fill(['parse_options' => $options->toArray()]);
        }

        if ($request->has('url')) {
            if ($feed->source !== 'url') {
                throw ValidationException::withMessages(['url' => 'This feed is fed by uploads; create a URL feed instead.']);
            }

            $target = UrlGuard::check((string) $request->validated('url'));
            // A new address: the old one's ETag and Last-Modified say nothing about it.
            FeedSource::query()->where('feed_id', $feed->id)->firstOrFail()
                ->fill(['url' => $target->url, 'host' => $target->host, 'etag' => null, 'last_modified' => null])
                ->save();
        }

        $feed->save();

        return new FeedResource($feed->load(['feedSource', 'latestImport']));
    }

    /** Delete a feed with its imports and stored files (owner or admin). */
    public function destroy(Request $request, Feed $feed, DeleteFeed $delete): Response
    {
        $this->authorizeManage($request);
        $delete->handle($feed);

        return response()->noContent();
    }
}
