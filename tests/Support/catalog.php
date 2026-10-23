<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Models\Tenant;

/* Catalogue helpers: approve a mapping (which syncs), read products and texts, add a translation. */

/** Approve the feed's drafted mapping as its owner (the sync starts on approval). */
function approveMapping(Tenant $tenant, Feed $feed): void
{
    $version = (int) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertOk()->json('mapping.version');
    test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feed->id}/mapping/approve", ['version' => $version])->assertOk();
}

function latestRun(Tenant $tenant, Feed $feed): PipelineRun
{
    return tenancy()->run($tenant->id, static fn (): PipelineRun => PipelineRun::query()->where('feed_id', $feed->id)->orderByDesc('id')->firstOrFail());
}

/** @return array<string, Product> sku => product */
function productsOf(Tenant $tenant, Feed $feed): array
{
    return tenancy()->run($tenant->id, static fn (): array => Product::query()->where('feed_id', $feed->id)->get()->keyBy('sku')->all());
}

/** @return array<string, ProductText> field@locale => text */
function textsOf(Tenant $tenant, Product $product): array
{
    return tenancy()->run($tenant->id, static fn (): array => ProductText::query()->where('product_id', $product->id)->get()
        ->keyBy(static fn (ProductText $text): string => "{$text->field}@{$text->locale}")->all());
}

/** A translation of the product's title, as the translator would store it. */
function translateTitle(Tenant $tenant, Product $product, string $from, string $value, string $origin = 'ai'): void
{
    tenancy()->run($tenant->id, static function () use ($product, $from, $value, $origin): void {
        $source = ProductText::query()->where('product_id', $product->id)->where('field', 'title')->where('locale', $from)->firstOrFail();
        ProductText::query()->forceCreate([
            'product_id' => $product->id, 'field' => 'title', 'locale' => 'en', 'role' => 'target', 'value' => $value,
            'status' => 'ready', 'origin' => $origin, 'source_hash' => $source->source_hash, 'source_locale' => $from,
        ]);
    });
}
