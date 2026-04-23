<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Validation;

use App\Services\Validation\GoldenRuleValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure-unit tests. No DB, no app boot. The validator takes patterns via the
 * constructor so we can exercise it without relying on config/feedgenix.php.
 */
class GoldenRuleValidatorTest extends TestCase
{
    private function make(?array $patterns = null): GoldenRuleValidator
    {
        return new GoldenRuleValidator(
            $patterns ?? ['price', 'stock', 'ean', 'sku', 'weight', 'dimension']
        );
    }

    #[Test]
    public function it_matches_a_pattern_as_a_substring_case_insensitively(): void
    {
        $v = $this->make(['price', 'prix', 'stock', 'ean', 'sku']);

        $this->assertTrue($v->isProtected('price'));
        $this->assertTrue($v->isProtected('PRICE'));
        $this->assertTrue($v->isProtected('Prix_Vente'));        // 'prix' substring, case-insensitive
        $this->assertTrue($v->isProtected('unit_price_eur'));    // 'price' is a substring
        $this->assertTrue($v->isProtected('EAN_13'));
        $this->assertTrue($v->isProtected('barcode_SKU'));
        $this->assertFalse($v->isProtected('title'));
        $this->assertFalse($v->isProtected('description_fr'));
    }

    #[Test]
    public function empty_patterns_never_protect_anything(): void
    {
        $v = $this->make([]);
        $this->assertFalse($v->isProtected('price'));
        $this->assertFalse($v->isProtected('stock'));
    }

    #[Test]
    public function empty_string_patterns_are_ignored_and_do_not_match_everything(): void
    {
        // Regression guard: str_contains($x, '') returns true. If we ever lose
        // the empty-pattern guard in the validator, every column would be
        // flagged protected and translation would silently collapse.
        $v = $this->make(['', 'price']);

        $this->assertTrue($v->isProtected('price'));
        $this->assertFalse($v->isProtected('title'));
    }

    #[Test]
    public function classify_splits_columns_into_protected_and_regular(): void
    {
        $v = $this->make();
        $columns = ['title_en', 'description_fr', 'price_eur', 'sku', 'weight_kg', 'image_url'];

        $result = $v->classify($columns);

        $this->assertSame(['price_eur', 'sku', 'weight_kg'], $result['protected']);
        $this->assertSame(['title_en', 'description_fr', 'image_url'], $result['regular']);
    }

    #[Test]
    public function validate_mapping_flags_protected_source_routed_to_translatable_target(): void
    {
        $v = $this->make();

        $mapping = [
            'title_en' => 'product_name',      // legit
            'title_fr' => 'prix_vente',        // violation — source looks like a price
            'description_en' => 'long_desc',   // legit
            'description_de' => 'stock_level', // violation
        ];
        $translatable = ['title_en', 'title_fr', 'description_en', 'description_de'];

        // prix_vente doesn't contain 'price', but 'stock_level' contains 'stock'.
        // Let's use patterns that match both.
        $v2 = $this->make(['prix', 'stock']);
        $violations = $v2->validateMapping($mapping, $translatable);

        $this->assertCount(2, $violations);
        $this->assertSame('title_fr', $violations[0]['target']);
        $this->assertSame('prix_vente', $violations[0]['source']);
        $this->assertSame('description_de', $violations[1]['target']);
        $this->assertSame('stock_level', $violations[1]['source']);
    }

    #[Test]
    public function validate_mapping_ignores_targets_that_are_not_in_translatable_list(): void
    {
        $v = $this->make();

        $mapping = [
            'code_article' => 'sku',  // protected source → but target is not translatable
        ];
        $translatable = [];

        $this->assertEmpty($v->validateMapping($mapping, $translatable));
    }

    #[Test]
    public function validate_mapping_ignores_empty_or_null_sources(): void
    {
        $v = $this->make();

        $mapping = [
            'title_en' => '',
            'title_fr' => null,
        ];
        /** @phpstan-ignore-next-line  null is intentional coverage for defensive behavior */
        $violations = $v->validateMapping($mapping, ['title_en', 'title_fr']);
        $this->assertEmpty($violations);
    }

    #[Test]
    public function passthrough_returns_only_protected_columns_with_original_values(): void
    {
        $v = $this->make();

        $row = [
            'title'      => 'Merino wool sweater',
            'price_eur'  => '149.00',
            'sku'        => 'MWS-001',
            'description'=> 'Lightweight',
            'weight_kg'  => '0.45',
        ];

        $out = $v->passthrough($row, array_keys($row));

        $this->assertSame(
            ['price_eur' => '149.00', 'sku' => 'MWS-001', 'weight_kg' => '0.45'],
            $out
        );
    }

    #[Test]
    public function passthrough_skips_protected_columns_that_are_missing_from_the_row(): void
    {
        $v = $this->make();

        $row = ['title' => 'x'];   // no protected columns present
        $this->assertSame([], $v->passthrough($row, ['title', 'price_eur', 'sku']));
    }

    #[Test]
    public function patterns_are_lowercased_and_deduplicated(): void
    {
        $v = $this->make(['PRICE', 'price', 'Stock', 'SKU', 'sku']);
        $this->assertSame(['price', 'stock', 'sku'], $v->patterns());
    }
}
