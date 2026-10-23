<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Translation\TextReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A product as a person reviews its texts in one language. Only texts: the golden fields are never
 * part of a review.
 *
 * @property Product $resource
 */
final class ProductReviewResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'product';

    /** @param Collection<int, ProductText> $rows */
    public function __construct(Product $product, private readonly Collection $rows, private readonly string $locale, private readonly ?string $preferred)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'sku' => $this->resource->sku,
            /**
             * Per text field: the source a translation is made from, and the translation (null when
             * there is none yet). "provided" is a text the feed has in this language itself: it is
             * not translated and changes in the feed only.
             *
             * @var list<array{field: string, label: string, provided: bool, source: array{locale: string, value: string, hash: string}|null, target: array{value: string|null, status: 'ready'|'pending'|'stale'|'qa_failed'|'held', origin: 'source'|'composed'|'ai'|'tm'|'manual'|'approved', stale: bool, failures: list<'empty'|'numbers'|'html'|'script'|'levels'|'length'|'echo'|'brands'>, hard: bool}|null}>
             */
            'texts' => resolve(TextReview::class)->texts($this->rows, $this->locale, $this->preferred),
        ];
    }
}
