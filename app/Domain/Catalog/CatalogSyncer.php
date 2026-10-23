<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Data\SyncPlan;
use App\Domain\Catalog\Data\SyncResult;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Ingest\Actions\ReadImportRows;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads a profiled import through its approved mapping into the catalogue. One code path for every
 * sync (legacy D10: the parallel path's disappear step had no guard), in chunks with bulk writes.
 *
 * - What the feed says now is what the catalogue holds: a blanked cell removes the value. (The
 *   legacy merge kept old values for ever, so bad data could never be cleared.)
 * - Golden and pass-through values are kept byte for byte, "0" and "n/a" included (rule 1).
 * - Texts are kept unless effectively empty. Each language column is kept as mapped: nothing is
 *   unset because texts in two languages look the same (legacy D9).
 * - A changed source makes its translations stale; a translation a person wrote is marked stale
 *   too, never overwritten.
 * - Products the import no longer lists are marked gone, unless that is more than 30% of a
 *   catalogue of over 50: then a warning, and nothing is removed.
 */
final readonly class CatalogSyncer
{
    public const int CHUNK = 500;

    public const float DISAPPEAR_MAX_SHARE = 0.30;

    public const int DISAPPEAR_MIN_CATALOGUE = 50;

    private const int JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private ReadImportRows $reader,
        private CanonicalSchema $schema,
    ) {}

    public function sync(Mapping $mapping, Import $import): SyncResult
    {
        $plan = SyncPlan::of($mapping, $this->schema);
        $result = new SyncResult;

        $chunk = [];
        foreach ($this->reader->rows($import) as $row) {
            $result->add('rows');
            $chunk[] = $row;
            if (count($chunk) === self::CHUNK) {
                $this->flush($plan, $import, $chunk, $result);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->flush($plan, $import, $chunk, $result);
        }

        $this->disappear($import, $result);

        return $result;
    }

    /** @param list<array<array-key, string>> $rows */
    private function flush(SyncPlan $plan, Import $import, array $rows, SyncResult $result): void
    {
        $candidates = [];
        foreach ($rows as $row) {
            $sku = (string) ($row[$plan->skuColumn] ?? '');
            if (trim($sku) === '') {
                $result->add('no_sku');

                continue;
            }
            if (isset($candidates[$sku])) {
                $result->add('duplicates');
            }
            $candidates[$sku] = ['values' => $this->values($plan, $row), 'texts' => $this->texts($plan, $row)];
        }
        if ($candidates === []) {
            return;
        }

        DB::transaction(function () use ($import, $candidates, $result): void {
            $skus = array_map(strval(...), array_keys($candidates));
            $before = Product::query()->where('feed_id', $import->feed_id)->whereIn('sku', $skus)
                ->get(['id', 'sku', 'values_hash', 'last_seen_import_id'])->keyBy('sku');

            $now = now();
            $upserts = [];
            foreach ($candidates as $sku => $candidate) {
                $json = json_encode((object) $candidate['values'], self::JSON);
                $upserts[] = [
                    'tenant_id' => $import->tenant_id,
                    'feed_id' => $import->feed_id,
                    'sku' => $sku,
                    'source_values' => $json,
                    'values_hash' => hash('sha256', $json),
                    'status' => 'active',
                    'first_seen_import_id' => $import->id,
                    'last_seen_import_id' => $import->id,
                    'disappeared_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $previous = $before->get($sku);
                if ($previous instanceof Product && $previous->last_seen_import_id === $import->id) {
                    $result->add('duplicates');
                }
            }
            DB::table('products')->upsert($upserts, ['feed_id', 'sku'], ['source_values', 'values_hash', 'status', 'last_seen_import_id', 'disappeared_at', 'updated_at']);

            $ids = Product::query()->where('feed_id', $import->feed_id)->whereIn('sku', $skus)->pluck('id', 'sku');
            $existingTexts = ProductText::query()->whereIn('product_id', $ids->values()->all())
                ->get(['id', 'product_id', 'field', 'locale', 'role', 'source_hash'])
                ->groupBy('product_id');

            foreach ($candidates as $sku => $candidate) {
                $productId = (int) $ids->get($sku);
                $previous = $before->get($sku);
                $valuesChanged = ! $previous instanceof Product
                    || $previous->values_hash !== hash('sha256', json_encode((object) $candidate['values'], self::JSON));
                $textsChanged = $this->syncTexts($import->tenant_id, $productId, $candidate['texts'], $existingTexts->get($productId)?->all() ?? [], $result);

                $result->add(match (true) {
                    ! $previous instanceof Product => 'new',
                    $valuesChanged || $textsChanged => 'changed',
                    default => 'unchanged',
                });
            }
        });
    }

    /**
     * @param  array<string, array{field: string, locale: string, value: string, origin: string}>  $texts
     * @param  array<array-key, ProductText>  $existing
     * @return bool whether any source text changed
     */
    private function syncTexts(int $tenantId, int $productId, array $texts, array $existing, SyncResult $result): bool
    {
        $byKey = [];
        foreach ($existing as $text) {
            $byKey["{$text->field}@{$text->locale}"] = $text;
        }

        $changed = false;
        $now = now();
        $inserts = [];
        foreach ($texts as $key => $text) {
            $hash = ProductText::hashOf($text['value']);
            $current = $byKey[$key] ?? null;
            if ($current instanceof ProductText && $current->role === 'source' && $current->source_hash === $hash) {
                continue;
            }
            $changed = true;
            if ($current instanceof ProductText) {
                // A changed source, or a language the feed now provides itself: the feed wins.
                ProductText::query()->whereKey($current->id)->update([
                    'role' => 'source', 'value' => $text['value'], 'status' => 'ready', 'origin' => $text['origin'],
                    'source_hash' => $hash, 'source_locale' => null, 'prompt' => null, 'model' => null, 'checks' => null, 'updated_at' => $now,
                ]);
            } else {
                $inserts[] = [
                    'tenant_id' => $tenantId, 'product_id' => $productId, 'field' => $text['field'], 'locale' => $text['locale'],
                    'role' => 'source', 'value' => $text['value'], 'status' => 'ready', 'origin' => $text['origin'],
                    'source_hash' => $hash, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $result->add('stale_translations', $this->staleTargets($productId, $text['field'], $text['locale'], $hash));
        }
        if ($inserts !== []) {
            DB::table('product_texts')->insert($inserts);
        }

        // Sources the feed no longer provides: gone, and what was translated from them is stale.
        foreach ($byKey as $key => $current) {
            if ($current->role === 'source' && ! isset($texts[$key])) {
                $changed = true;
                ProductText::query()->whereKey($current->id)->delete();
                $result->add('stale_translations', $this->staleTargets($productId, $current->field, $current->locale, null));
            }
        }

        return $changed;
    }

    /** Translations of this source that were made from another version of it. */
    private function staleTargets(int $productId, string $field, string $sourceLocale, ?string $hash): int
    {
        $query = ProductText::query()
            ->where('product_id', $productId)
            ->where('field', $field)
            ->where('role', 'target')
            ->where('source_locale', $sourceLocale)
            ->where('status', '<>', 'stale');
        if ($hash !== null) {
            $query->where(static fn (Builder $q) => $q->whereNull('source_hash')->orWhere('source_hash', '<>', $hash));
        }

        return $query->update(['status' => 'stale', 'updated_at' => now()]);
    }

    private function disappear(Import $import, SyncResult $result): void
    {
        $active = Product::query()->where('feed_id', $import->feed_id)->where('status', 'active');
        $total = (clone $active)->count();
        $unseen = (clone $active)->where(static fn (Builder $q) => $q->whereNull('last_seen_import_id')->orWhere('last_seen_import_id', '<>', $import->id));
        $gone = (clone $unseen)->count();
        if ($gone === 0) {
            return;
        }
        if ($total > self::DISAPPEAR_MIN_CATALOGUE && $gone > (int) ceil($total * self::DISAPPEAR_MAX_SHARE)) {
            $result->warn('mass_disappearance', "{$gone} of {$total} products are missing from this file. Nothing was removed: if the file is complete, confirm the removal.", $gone);

            return;
        }

        $result->add('disappeared', $unseen->update(['status' => 'disappeared', 'disappeared_at' => now(), 'updated_at' => now()]));
    }

    /**
     * @param  array<array-key, string>  $row
     * @return array<string, string> golden and pass-through values, exact; blank cells are absent
     */
    private function values(SyncPlan $plan, array $row): array
    {
        $values = [];
        foreach ($plan->values as $field => $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && trim($value) !== '') {
                $values[$field] = $value;
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * @param  array<array-key, string>  $row
     * @return array<string, array{field: string, locale: string, value: string, origin: string}>
     */
    private function texts(SyncPlan $plan, array $row): array
    {
        $texts = [];
        foreach ($plan->texts as $key => $source) {
            $value = $source['recipe'] !== null ? $source['recipe']->compose($row) : (string) ($row[(string) $source['column']] ?? '');
            if (! EmptyValue::isEmpty($value)) {
                $texts[$key] = ['field' => $source['field'], 'locale' => $source['locale'], 'value' => $value, 'origin' => $source['recipe'] !== null ? 'composed' : 'source'];
            }
        }

        return $texts;
    }
}
