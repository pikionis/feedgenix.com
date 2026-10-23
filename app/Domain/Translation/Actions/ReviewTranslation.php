<?php

declare(strict_types=1);

namespace App\Domain\Translation\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\Exceptions\SourceChanged;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A person's word on one translation: write it, approve the AI's as it is, or hand it back to the
 * AI. Each is fenced by the source the person saw (a text changed in the feed meanwhile is refused),
 * only ever touches a translation (never the feed's own text, never a golden field), and keeps the
 * translation memory in step: a person's translation is reused for the same text, and a text handed
 * back is forgotten so the AI really translates it again.
 */
final readonly class ReviewTranslation
{
    public function __construct(private CanonicalSchema $schema) {}

    /**
     * @throws SourceChanged
     * @throws InvalidArgumentException
     */
    public function write(Product $product, string $field, string $locale, string $sourceLocale, string $sourceHash, string $value): void
    {
        $this->guard($product, $field, $locale);
        if (trim($value) === '') {
            throw new InvalidArgumentException('Write the translation, or hand the text back to the AI.');
        }
        $this->source($product, $field, $sourceLocale, $sourceHash);

        DB::transaction(function () use ($product, $field, $locale, $sourceLocale, $sourceHash, $value): void {
            $written = DB::affectingStatement(<<<'SQL'
                INSERT INTO product_texts (tenant_id, product_id, field, locale, role, value, status, origin, source_hash, source_locale, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'target', ?, 'ready', 'manual', ?, ?, now(), now())
                ON CONFLICT (product_id, field, locale) DO UPDATE SET
                    value = EXCLUDED.value, status = 'ready', origin = 'manual', source_hash = EXCLUDED.source_hash,
                    source_locale = EXCLUDED.source_locale, prompt = NULL, model = NULL, checks = NULL, updated_at = now()
                WHERE product_texts.role = 'target'
                SQL, [$product->tenant_id, $product->id, $field, $locale, $value, $sourceHash, $sourceLocale]);
            if ($written === 0) {
                throw new InvalidArgumentException('The feed provides this text in this language: change it in the feed.');
            }
            DB::table('translation_memory')->upsert([[
                'tenant_id' => $product->tenant_id, 'field' => $field, 'source_locale' => $sourceLocale, 'target_locale' => $locale,
                'source_hash' => $sourceHash, 'value' => $value, 'prompt' => null, 'model' => 'manual', 'hits' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['tenant_id', 'field', 'source_locale', 'target_locale', 'source_hash'], ['value', 'prompt', 'model', 'updated_at']);
        });
        Feed::contentChanged($product->feed_id);
    }

    /**
     * Publish the AI's translation as it is, though a check flagged it.
     *
     * @throws SourceChanged
     * @throws InvalidArgumentException
     */
    public function approve(Product $product, string $field, string $locale, string $sourceHash): void
    {
        $this->guard($product, $field, $locale);
        $target = ProductText::query()->where('product_id', $product->id)->where('field', $field)->where('locale', $locale)
            ->where('role', 'target')->where('origin', 'ai')->whereIn('status', ['ready', 'qa_failed'])->first();
        if (! $target instanceof ProductText || $target->value === null || $target->source_locale === null) {
            throw new InvalidArgumentException('There is no translation by the AI to approve here.');
        }
        if ($target->source_hash !== $sourceHash) {
            throw new SourceChanged;
        }
        $this->source($product, $field, $target->source_locale, $sourceHash);

        DB::transaction(static function () use ($product, $target, $field, $locale, $sourceHash): void {
            $target->forceFill(['status' => 'ready', 'origin' => 'approved'])->save();
            DB::table('translation_memory')->insertOrIgnore([
                'tenant_id' => $product->tenant_id, 'field' => $field, 'source_locale' => $target->source_locale, 'target_locale' => $locale,
                'source_hash' => $sourceHash, 'value' => $target->value, 'prompt' => $target->prompt, 'model' => $target->model, 'hits' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Feed::contentChanged($product->feed_id);
    }

    /** Hand the text back to the AI: the next run translates it again, without the memory's answer. */
    public function reset(Product $product, string $field, string $locale): void
    {
        $this->guard($product, $field, $locale);
        DB::transaction(static function () use ($product, $field, $locale): void {
            $target = ProductText::query()->where('product_id', $product->id)->where('field', $field)->where('locale', $locale)->where('role', 'target')->first();
            if (! $target instanceof ProductText) {
                return;
            }
            if ($target->source_hash !== null && $target->source_locale !== null) {
                DB::table('translation_memory')->where('field', $field)->where('source_locale', $target->source_locale)
                    ->where('target_locale', $locale)->where('source_hash', $target->source_hash)->delete();
            }
            $target->delete();
        });
        Feed::contentChanged($product->feed_id);
    }

    /** A text field, and a language of the workspace. */
    private function guard(Product $product, string $field, string $locale): void
    {
        if (! $this->schema->has($field) || ! $this->schema->get($field)->isLocalized()) {
            throw new InvalidArgumentException("[{$field}] is not a text that is translated.");
        }
        if (! TenantLanguage::query()->where('language_code', $locale)->exists()) {
            throw new InvalidArgumentException("[{$locale}] is not one of the workspace's languages.");
        }
        if ($product->status !== 'active') {
            throw new InvalidArgumentException('This product is no longer in the feed.');
        }
    }

    /** @throws SourceChanged unless the product still has this source text */
    private function source(Product $product, string $field, string $sourceLocale, string $sourceHash): void
    {
        $current = ProductText::query()->where('product_id', $product->id)->where('field', $field)->where('locale', $sourceLocale)
            ->where('role', 'source')->value('source_hash');
        if ($current !== $sourceHash) {
            throw new SourceChanged;
        }
    }
}
