<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mapping;

use App\Services\Mapping\TranslatableFieldDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranslatableFieldDetectorTest extends TestCase
{
    private TranslatableFieldDetector $det;

    protected function setUp(): void
    {
        parent::setUp();
        $this->det = new TranslatableFieldDetector();
    }

    #[Test]
    public function empty_column_is_flagged_empty_with_full_confidence(): void
    {
        $r = $this->det->classify([null, '', '   ', null]);
        $this->assertSame(TranslatableFieldDetector::KIND_EMPTY, $r['kind']);
        $this->assertSame(1.0, $r['confidence']);
        $this->assertSame(0, $r['non_empty_count']);
    }

    #[Test]
    public function pure_numbers_are_classified_numeric(): void
    {
        $r = $this->det->classify(['12.50', '149', '-4.99', '0', '1000']);
        $this->assertSame(TranslatableFieldDetector::KIND_NUMERIC, $r['kind']);
    }

    #[Test]
    public function trailing_currency_notation_is_classified_numeric(): void
    {
        // Swiss / EU feeds canonically put currency after the amount. Detector
        // only needs to recognize this common form — Golden Rule catches
        // anything it misses by matching on the column name.
        $r = $this->det->classify(['149.00 CHF', '299 EUR', '9.99', '100', '49']);
        $this->assertSame(TranslatableFieldDetector::KIND_NUMERIC, $r['kind']);
    }

    #[Test]
    public function size_codes_are_classified_code(): void
    {
        // S / M / L / XL / XXL / size-number patterns — a size column looks like this
        $r = $this->det->classify(['XS', 'S', 'M', 'L', 'XL', 'XXL']);
        $this->assertSame(TranslatableFieldDetector::KIND_CODE, $r['kind']);
    }

    #[Test]
    public function enum_tokens_are_classified_code(): void
    {
        // gender_male, color_red, status_active
        $r = $this->det->classify(['gender_male', 'gender_female', 'color_red', 'color_blue']);
        $this->assertSame(TranslatableFieldDetector::KIND_CODE, $r['kind']);
    }

    #[Test]
    public function very_short_whitespaceless_tokens_are_classified_code(): void
    {
        $r = $this->det->classify(['A', 'BB', 'Cc', 'Dd', 'EEE']);
        $this->assertSame(TranslatableFieldDetector::KIND_CODE, $r['kind']);
    }

    #[Test]
    public function real_sentences_are_classified_translatable(): void
    {
        $r = $this->det->classify([
            'Merino wool sweater, ribbed crew neck',
            'Waterproof hiking boot with vibram sole',
            'Lightweight breathable cotton t-shirt',
            'Leather wallet in navy blue',
            'Ceramic coffee mug, handmade',
        ]);
        $this->assertSame(TranslatableFieldDetector::KIND_TRANSLATABLE, $r['kind']);
        $this->assertGreaterThan(0.85, $r['confidence']);
    }

    #[Test]
    public function majority_of_codes_tips_a_mixed_column_into_code_kind(): void
    {
        // 4 codes, 1 sentence → 80% codes → code bucket (>= 70% threshold)
        $r = $this->det->classify([
            'S', 'M', 'L', 'XL',
            'Really long sentence that is clearly translatable product copy',
        ]);
        $this->assertSame(TranslatableFieldDetector::KIND_CODE, $r['kind']);
    }

    #[Test]
    public function majority_translatable_with_some_codes_stays_translatable(): void
    {
        // 3 sentences, 2 codes → only 40% codes → below threshold → translatable
        $r = $this->det->classify([
            'A long descriptive sentence about the product.',
            'Another marketing description with benefits.',
            'Third product copy line with some detail.',
            'XL',
            'S',
        ]);
        $this->assertSame(TranslatableFieldDetector::KIND_TRANSLATABLE, $r['kind']);
        // Translatable ratio = 3/5 = 0.6
        $this->assertEqualsWithDelta(0.6, $r['translatable_ratio'], 0.01);
    }

    #[Test]
    public function whitespace_only_values_are_treated_as_empty(): void
    {
        // Mixed real values + whitespace-only should classify by non-empty subset only
        $r = $this->det->classify(['   ', '', 'Real text here', 'Another real value', null]);
        $this->assertSame(2, $r['non_empty_count']);
        $this->assertSame(TranslatableFieldDetector::KIND_TRANSLATABLE, $r['kind']);
    }

    #[Test]
    public function classify_all_handles_each_column_independently(): void
    {
        $rows = [
            ['title' => 'Merino wool sweater', 'price' => '149.00', 'size' => 'M', 'empty_col' => ''],
            ['title' => 'Waterproof boot',     'price' => '89.90',  'size' => 'L', 'empty_col' => null],
            ['title' => 'Cotton shirt',        'price' => '29.00',  'size' => 'S', 'empty_col' => ''],
        ];

        $out = $this->det->classifyAll($rows, ['title', 'price', 'size', 'empty_col']);

        $this->assertSame(TranslatableFieldDetector::KIND_TRANSLATABLE, $out['title']['kind']);
        $this->assertSame(TranslatableFieldDetector::KIND_NUMERIC,      $out['price']['kind']);
        $this->assertSame(TranslatableFieldDetector::KIND_CODE,         $out['size']['kind']);
        $this->assertSame(TranslatableFieldDetector::KIND_EMPTY,        $out['empty_col']['kind']);
    }

    #[Test]
    public function guess_language_returns_null_for_too_short_input(): void
    {
        $this->assertNull($this->det->guessLanguage(''));
        $this->assertNull($this->det->guessLanguage('hi'));
    }

    #[Test]
    public function guess_language_identifies_common_european_languages(): void
    {
        $this->assertSame('de', $this->det->guessLanguage('Das ist ein Pullover aus Merinowolle mit Rundhalsausschnitt'));
        $this->assertSame('fr', $this->det->guessLanguage('Les pulls en laine mérinos avec une coupe très confortable'));
        $this->assertSame('it', $this->det->guessLanguage('Questo è un maglione di lana merino con colore molto intenso'));
        $this->assertSame('es', $this->det->guessLanguage('Los productos están hechos con lana merino de muy alta calidad'));
    }

    #[Test]
    public function guess_language_defaults_to_english_when_nothing_matches(): void
    {
        $this->assertSame('en', $this->det->guessLanguage('Merino wool sweater ribbed crew neck size medium color navy'));
    }

    #[Test]
    public function dominant_language_considers_only_translatable_columns(): void
    {
        $rows = [
            ['title' => 'Das ist ein Pullover aus Merinowolle', 'price' => '149.00'],
            ['title' => 'Ein weiteres Produkt mit Rundhalsausschnitt', 'price' => '89.00'],
            ['title' => 'Drittes Produkt mit Größe und Farbe', 'price' => '29.00'],
        ];

        $lang = $this->det->detectDominantLanguage($rows, ['title']);
        $this->assertSame('de', $lang);
    }

    #[Test]
    public function dominant_language_returns_null_when_all_translatable_columns_are_empty(): void
    {
        $rows = [['title' => '', 'price' => '10']];
        $this->assertNull($this->det->detectDominantLanguage($rows, ['title']));
    }
}
