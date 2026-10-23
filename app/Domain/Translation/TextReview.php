<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Feeds\Models\Feed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The products of a feed as a person reviews their texts in one language: what to look at (failed
 * or flagged checks, stale translations), what is missing, what a person wrote. A language the
 * feed provides itself is shown as the feed's, never as something to translate.
 */
final readonly class TextReview
{
    public const array FILTERS = ['all', 'review', 'missing', 'manual'];

    public function __construct(
        private TranslationPlanner $planner,
        private CanonicalSchema $schema,
    ) {}

    /** @return Builder<Product> active products, by SKU */
    public function products(Feed $feed, string $locale, string $filter, ?string $search): Builder
    {
        $query = Product::query()->where('feed_id', $feed->id)->where('status', 'active')->orderBy('sku');
        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where(static fn (Builder $match): Builder => $match->where('sku', 'ilike', $like)->orWhereExists(
                static fn (QueryBuilder $title): QueryBuilder => $title->from('product_texts')->whereColumn('product_texts.product_id', 'products.id')
                    ->where('product_texts.field', 'title')->where('product_texts.value', 'ilike', $like),
            ));
        }

        return match ($filter) {
            'review' => $query->whereExists(fn (QueryBuilder $texts): QueryBuilder => $this->toReview($texts, $locale)),
            'missing' => $query->whereExists(fn (QueryBuilder $texts): QueryBuilder => $this->missing($texts, $feed, $locale)),
            'manual' => $query->whereExists(static fn (QueryBuilder $texts): QueryBuilder => self::targets($texts, $locale)->where('t.origin', 'manual')),
            default => $query,
        };
    }

    /** @return array{all: int, review: int, missing: int, manual: int} how many products each filter shows */
    public function counts(Feed $feed, string $locale): array
    {
        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->products($feed, $locale, $filter, null)->toBase()->getCountForPagination();
        }

        /** @var array{all: int, review: int, missing: int, manual: int} $counts */
        return $counts;
    }

    /**
     * The product's texts in the language: per text field it has, the source a translation is made
     * from (the mapped feed language first) and the translation, or the feed's own text.
     *
     * @param  Collection<int, ProductText>  $rows  the product's sources and its targets in the language
     * @return list<array{field: string, label: string, provided: bool, source: array{locale: string, value: string, hash: string}|null, target: array{value: string|null, status: string, origin: string, stale: bool, failures: list<string>, hard: bool}|null}>
     */
    public function texts(Collection $rows, string $locale, ?string $preferred): array
    {
        $texts = [];
        foreach ($rows->groupBy('field') as $field => $group) {
            $field = (string) $field;
            if (! $this->schema->has($field) || ! $this->schema->get($field)->isLocalized()) {
                continue;
            }
            $own = $group->first(static fn (ProductText $text): bool => $text->locale === $locale);
            $target = $own instanceof ProductText && $own->role === 'target' ? $own : null;
            // The source the translation was made from while it exists, otherwise the one the next would use.
            $source = $group->filter(static fn (ProductText $text): bool => $text->role === 'source' && $text->locale !== $locale)
                ->sortBy(static fn (ProductText $text): string => ($text->locale === ($target->source_locale ?? $preferred) ? '0' : ($text->locale === $preferred ? '1' : '2')).$text->locale)
                ->first();
            $checks = $target->checks ?? [];
            $failures = is_array($checks['failures'] ?? null) ? array_values(array_map(strval(...), $checks['failures'])) : [];

            $texts[] = [
                'field' => $field,
                'label' => $this->schema->get($field)->label,
                'provided' => $own instanceof ProductText && $own->role === 'source',
                'source' => $source instanceof ProductText ? ['locale' => $source->locale, 'value' => (string) $source->value, 'hash' => (string) $source->source_hash] : null,
                'target' => $target instanceof ProductText ? [
                    'value' => $target->value,
                    'status' => $target->status,
                    'origin' => $target->origin,
                    'stale' => $target->status === 'stale' || ! $source instanceof ProductText || $source->locale !== $target->source_locale || $target->source_hash !== $source->source_hash,
                    'failures' => $failures,
                    'hard' => $target->status === 'qa_failed',
                ] : null,
            ];
        }

        return $texts;
    }

    public function preferredSource(Feed $feed): ?string
    {
        return $this->planner->preferredSource($feed);
    }

    /** Failed or flagged checks, or a translation whose source changed. */
    private function toReview(QueryBuilder $texts, string $locale): QueryBuilder
    {
        return self::targets($texts, $locale)->where(static fn (QueryBuilder $flagged): QueryBuilder => $flagged
            ->whereIn('t.status', ['qa_failed', 'stale'])
            ->orWhere(static fn (QueryBuilder $soft): QueryBuilder => $soft->where('t.origin', 'ai')->where('t.status', 'ready')
                ->whereRaw("jsonb_array_length(coalesce(t.checks->'failures', '[]'::jsonb)) > 0")));
    }

    /** A source in another language with nothing in this one, for a field the plan does not leave out. */
    private function missing(QueryBuilder $texts, Feed $feed, string $locale): QueryBuilder
    {
        $skipped = array_keys(array_filter($this->planner->planOf($feed)->fields, static fn (mixed $mode): bool => $mode === 'skip'));

        return $texts->from('product_texts AS s')->whereColumn('s.product_id', 'products.id')
            ->where('s.role', 'source')->where('s.locale', '<>', $locale)
            ->when($skipped !== [], static fn (QueryBuilder $fields): QueryBuilder => $fields->whereNotIn('s.field', $skipped))
            ->whereNotExists(static fn (QueryBuilder $own): QueryBuilder => $own->from('product_texts AS o')
                ->whereColumn('o.product_id', 's.product_id')->whereColumn('o.field', 's.field')->where('o.locale', $locale));
    }

    private static function targets(QueryBuilder $texts, string $locale): QueryBuilder
    {
        return $texts->from('product_texts AS t')->whereColumn('t.product_id', 'products.id')->where('t.role', 'target')->where('t.locale', $locale);
    }
}
