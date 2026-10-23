<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Translation\Actions\ReviewTranslation;
use App\Domain\Translation\Exceptions\SourceChanged;
use App\Domain\Translation\TextReview;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Resources\ProductReviewResource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ProductTextController
{
    use ActsInWorkspace;

    public const string LOCALE = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/';

    /**
     * The feed's products with their texts in one language, 25 a page, by SKU: all of them, those
     * to review (a failed or flagged check, a changed source), those missing a translation, or
     * those a person translated. With how many products each filter shows.
     */
    public function index(Request $request, Feed $feed, TextReview $review): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:16', 'regex:'.self::LOCALE],
            'filter' => ['sometimes', 'string', Rule::in(TextReview::FILTERS)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $locale = (string) $data['locale'];
        $page = $review->products($feed, $locale, (string) ($data['filter'] ?? 'all'), isset($data['search']) ? (string) $data['search'] : null)->paginate(25);
        $rows = $this->rows($page->getCollection()->modelKeys(), $locale);
        $preferred = $review->preferredSource($feed);

        return response()->json([
            'data' => $page->getCollection()->map(static fn (Product $product): ProductReviewResource => new ProductReviewResource($product, $rows->get($product->id, collect()), $locale, $preferred))->values()->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => $review->counts($feed, $locale),
            ],
        ]);
    }

    /**
     * Write a translation yourself (owner or admin). It is never replaced by the AI, and the same
     * text elsewhere reuses it. Send the source you translated: 409 when it changed in the feed.
     *
     * @throws SourceChanged
     */
    public function update(Request $request, Product $product, string $field, string $locale, ReviewTranslation $review): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'value' => ['required', 'string', 'max:20000'],
            'source_locale' => ['required', 'string', 'max:16'],
            'source_hash' => ['required', 'string', 'size:64'],
        ]);
        $this->apply(static fn () => $review->write($product, $field, $locale, (string) $data['source_locale'], (string) $data['source_hash'], (string) $data['value']));

        return $this->respond($product, $locale);
    }

    /**
     * Publish the AI's translation as it is, though a check flagged it (owner or admin). 409 when
     * the source changed in the feed.
     *
     * @throws SourceChanged
     */
    public function approve(Request $request, Product $product, string $field, string $locale, ReviewTranslation $review): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['source_hash' => ['required', 'string', 'size:64']]);
        $this->apply(static fn () => $review->approve($product, $field, $locale, (string) $data['source_hash']));

        return $this->respond($product, $locale);
    }

    /** Hand the text back to the AI (owner or admin): the next translation run translates it again. */
    public function destroy(Request $request, Product $product, string $field, string $locale, ReviewTranslation $review): JsonResponse
    {
        $this->authorizeManage($request);
        $this->apply(static fn () => $review->reset($product, $field, $locale));

        return $this->respond($product, $locale);
    }

    private function apply(callable $change): void
    {
        try {
            $change();
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['value' => $invalid->getMessage()]);
        }
    }

    private function respond(Product $product, string $locale): JsonResponse
    {
        $feed = Feed::query()->findOrFail($product->feed_id);
        $preferred = resolve(TextReview::class)->preferredSource($feed);

        return (new ProductReviewResource($product, $this->rows([$product->id], $locale)->get($product->id, collect()), $locale, $preferred))->response()->setStatusCode(200);
    }

    /**
     * @param  array<int, mixed>  $productIds
     * @return Collection<array-key, Collection<int, ProductText>> product id => its texts
     */
    private function rows(array $productIds, string $locale): Collection
    {
        return ProductText::query()->whereIn('product_id', $productIds)
            ->where(static fn (Builder $texts) => $texts->where('role', 'source')->orWhere('locale', $locale))
            ->orderBy('id')->get()->toBase()->groupBy('product_id');
    }
}
