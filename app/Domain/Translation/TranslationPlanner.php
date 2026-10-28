<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\TranslationMode;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\Models\TranslationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * What a feed's translation plan means in work: per field and target language, the texts with no
 * translation yet, or a stale or failed one. The source is chosen per product: the feed's language
 * when the product has it, otherwise another language it has (the legacy plan chose one source
 * language for the whole catalogue, so products written in another one were never translated). A
 * translation a person wrote is never redone, and the translation memory covers repeats for free.
 */
final readonly class TranslationPlanner
{
    public function __construct(private CanonicalSchema $schema) {}

    /** The stored plan, or the defaults: every field with a source, in its default mode, into every workspace language. */
    public function planOf(Feed $feed): TranslationPlan
    {
        $plan = TranslationPlan::query()->where('feed_id', $feed->id)->first();
        if ($plan instanceof TranslationPlan) {
            return $plan;
        }

        $fields = [];
        foreach ($this->sources($feed) as $source) {
            $mode = $this->schema->has($source['field']) ? $this->schema->get($source['field'])->defaultMode : null;
            $fields[$source['field']] = $mode === TranslationMode::Keep ? 'keep' : 'translate';
        }

        return (new TranslationPlan)->forceFill([
            'feed_id' => $feed->id,
            'fields' => $fields,
            'target_locales' => TenantLanguage::activeCodes(),
        ]);
    }

    /** @return list<array{field: string, locale: string, products: int}> the feed's source texts by field and language */
    public function sources(Feed $feed): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.field, t.locale, count(*) AS products
            FROM product_texts t JOIN products p ON p.id = t.product_id
            WHERE p.feed_id = ? AND p.status = 'active' AND t.role = 'source'
            GROUP BY t.field, t.locale
            ORDER BY t.field, t.locale
            SQL, [$feed->id]);

        return array_values(array_map(static function (object $row): array {
            $values = (array) $row;

            return ['field' => (string) $values['field'], 'locale' => (string) $values['locale'], 'products' => (int) $values['products']];
        }, $rows));
    }

    /**
     * The plan's target languages that take new translations: a language the plan paused keeps
     * its texts and its place in the plan, and gets nothing new until it is active again.
     *
     * @return list<string>
     */
    public function targets(TranslationPlan $plan): array
    {
        return array_values(array_intersect($plan->target_locales, TenantLanguage::activeCodes()));
    }

    /** The language a product's text is translated from when it has several: the mapped feed language. */
    public function preferredSource(Feed $feed): ?string
    {
        $locale = Mapping::query()->where('feed_id', $feed->id)->where('status', 'approved')->latest('approved_at')->value('source_locale');

        return is_string($locale) ? $locale : null;
    }

    /**
     * Texts of one field to translate into one language, oldest product first.
     *
     * @return LazyCollection<int, \stdClass> rows of product_id, source_locale, source_hash, value and memory (a remembered translation, or null)
     */
    public function pending(Feed $feed, string $field, string $target, ?string $preferred): LazyCollection
    {
        return DB::query()
            ->fromRaw('('.self::PENDING.') AS work', [$feed->id, $field, $target, (string) $preferred, $field, $target, $field, $target])
            ->lazyById(1000, 'product_id', 'product_id');
    }

    /**
     * Per field and target language: how many texts need work, their size, and how many the memory covers.
     *
     * @return list<array{field: string, target_locale: string, mode: string, texts: int, characters: int, bytes: int, memory: int}>
     */
    public function work(Feed $feed, TranslationPlan $plan): array
    {
        $preferred = $this->preferredSource($feed);
        $lines = [];
        foreach ($plan->fields as $field => $mode) {
            if ($mode === 'skip') {
                continue;
            }
            foreach ($this->targets($plan) as $target) {
                $row = DB::selectOne('SELECT count(*) AS texts, coalesce(sum(char_length(value)), 0) AS characters, coalesce(sum(octet_length(value)) FILTER (WHERE memory IS NULL), 0) AS bytes, count(memory) AS memory FROM ('.self::PENDING.') AS work',
                    [$feed->id, $field, $target, (string) $preferred, $field, $target, $field, $target]);
                $values = is_object($row) ? (array) $row : [];
                $lines[] = [
                    'field' => (string) $field,
                    'target_locale' => (string) $target,
                    'mode' => (string) $mode,
                    'texts' => (int) ($values['texts'] ?? 0),
                    'characters' => (int) ($values['characters'] ?? 0),
                    // What the AI would read: texts the memory does not cover.
                    'bytes' => (int) ($values['bytes'] ?? 0),
                    'memory' => (int) ($values['memory'] ?? 0),
                ];
            }
        }

        return $lines;
    }

    /**
     * The products a start of the plan would send to AI (the allowance counts these): texts to
     * translate that the memory does not cover. Kept fields and repeats count nothing.
     *
     * @return list<int>
     */
    public function productsForAi(Feed $feed, TranslationPlan $plan): array
    {
        $preferred = $this->preferredSource($feed);
        $products = [];
        foreach ($plan->fields as $field => $mode) {
            if ($mode !== 'translate') {
                continue;
            }
            foreach ($this->targets($plan) as $target) {
                $rows = DB::select('SELECT DISTINCT product_id FROM ('.self::PENDING.') AS work WHERE memory IS NULL',
                    [$feed->id, $field, $target, (string) $preferred, $field, $target, $field, $target]);
                foreach ($rows as $row) {
                    $products[(int) ((array) $row)['product_id']] = true;
                }
            }
        }

        return array_keys($products);
    }

    /*
     * One row per product that has the field in another language and no fresh translation into the
     * target: none yet, or pending, stale, failed or held, or made from another version of the
     * source. Never a translation a person wrote, and never a language the feed provides itself.
     * Bindings: feed, field, target, preferred source, field, target, field, target.
     */
    private const string PENDING = <<<'SQL'
        SELECT src.product_id, src.source_locale, src.source_hash, src.value, m.value AS memory
        FROM (
            SELECT DISTINCT ON (s.product_id) s.product_id, s.locale AS source_locale, s.source_hash, s.value
            FROM product_texts s JOIN products p ON p.id = s.product_id
            WHERE p.feed_id = ? AND p.status = 'active' AND s.role = 'source' AND s.field = ? AND s.locale <> ?
            ORDER BY s.product_id, (s.locale = ?) DESC, s.locale
        ) AS src
        LEFT JOIN product_texts t ON t.product_id = src.product_id AND t.field = ? AND t.locale = ?
        LEFT JOIN translation_memory m ON m.field = ? AND m.source_locale = src.source_locale AND m.target_locale = ? AND m.source_hash = src.source_hash
        WHERE t.id IS NULL
           OR (t.role = 'target' AND t.origin <> 'manual'
               AND (t.status IN ('pending', 'stale', 'qa_failed', 'held') OR t.source_hash IS DISTINCT FROM src.source_hash))
        SQL;
}
