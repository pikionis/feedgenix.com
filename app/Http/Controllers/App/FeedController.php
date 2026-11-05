<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Billing\PlanLimitsApplier;
use App\Domain\Feeds\Actions\ChangeFeedSource;
use App\Domain\Feeds\Actions\CreateFeed;
use App\Domain\Feeds\Actions\DeleteFeed;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Actions\QueueImport;
use App\Domain\Ingest\FeedSizeLimit;
use App\Domain\Ingest\ParseOptions;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Requests\App\StoreFeedRequest;
use App\Http\Requests\App\UpdateFeedRequest;
use App\Http\Resources\FeedResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class FeedController
{
    use ActsInWorkspace;

    /** The workspace's feeds, each with its latest import. */
    public function index(): AnonymousResourceCollection
    {
        $feeds = Feed::query()->withSummary()->orderBy('id')->get();

        return FeedResource::collection($feeds);
    }

    /**
     * Create a feed (owner or admin).
     *
     * With a URL the first download starts at once; without one the feed waits for an upload. The
     * plan's feed limit applies: over it, a 402 names the plan that fits. With `upload_size`, the
     * first file must also fit the plan's feed size (402), checked before anything is made.
     *
     * @throws PlanLimitExceeded
     */
    public function store(StoreFeedRequest $request, CreateFeed $create, QueueImport $queue): JsonResponse
    {
        $this->authorizeManage($request);

        $uploadSize = $request->uploadSize();
        if ($uploadSize !== null) {
            FeedSizeLimit::for(Entitlements::for($this->workspace()))->assertUploadFits($uploadSize, 'upload_size');
        }

        $feed = $create->handle($this->workspace(), $request->name(), $request->url(), $request->parseOptions(), $request->login(), $request->hostKey());
        if ($feed->source === 'url') {
            $queue->fetch($feed);
        }

        return $this->summary($feed)->response()->setStatusCode(201);
    }

    /** A feed with its latest import. */
    public function show(Feed $feed): FeedResource
    {
        return $this->summary($feed);
    }

    /**
     * Rename a feed, change its URL, its login, its trusted server key or its reading options (owner
     * or admin). What is not sent is kept: an omitted URL or login never clears the stored one, and
     * `auth: null` removes the login. A new URL on another origin also drops the stored login and
     * server key, unless new ones come with it.
     */
    public function update(UpdateFeedRequest $request, Feed $feed, ChangeFeedSource $change): FeedResource
    {
        $this->authorizeManage($request);

        if ($request->has('name')) {
            $feed->fill(['name' => trim((string) $request->validated('name'))]);
        }

        $options = $request->parseOptions();
        if ($options instanceof ParseOptions) {
            $feed->fill(['parse_options' => $options->toArray()]);
        }

        if ($request->changesSource()) {
            $change->handle($feed, $request->sourceChange());
        }

        $feed->save();

        return $this->summary($feed);
    }

    /** Delete a feed with its imports and stored files (owner or admin). */
    public function destroy(Request $request, Feed $feed, DeleteFeed $delete, PlanLimitsApplier $limits): Response
    {
        $this->authorizeManage($request);
        $delete->handle($feed);
        // Room for a feed the plan paused.
        $limits->apply($this->workspace());

        return response()->noContent();
    }

    private function summary(Feed $feed): FeedResource
    {
        return new FeedResource(Feed::query()->withSummary()->findOrFail($feed->id));
    }
}
