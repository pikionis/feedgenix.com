<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Services\Import\CsvParser;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    /** @var string[] absolute paths to clean up after the test */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        parent::tearDown();
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvp_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    #[Test]
    public function it_parses_comma_delimited_csv_with_headers_and_samples(): void
    {
        $path = $this->tempFile("sku,title,price\nABC-1,Merino sweater,149.00\nABC-2,Waterproof boot,89.90\nABC-3,Cotton shirt,29.00\n");
        $p = new CsvParser();

        $r = $p->preview($path, 5);

        $this->assertSame(['sku', 'title', 'price'], $r['headers']);
        $this->assertSame(3, $r['row_count']);
        $this->assertSame(3, $r['column_count']);
        $this->assertCount(3, $r['samples']);
        $this->assertSame(['sku' => 'ABC-1', 'title' => 'Merino sweater', 'price' => '149.00'], $r['samples'][0]);
        $this->assertSame(',', $r['delimiter']);
    }

    #[Test]
    public function it_auto_detects_semicolon_delimiter(): void
    {
        $path = $this->tempFile("sku;title;price\nABC-1;Merino sweater;149.00\nABC-2;Waterproof boot;89.90\n");
        $p = new CsvParser();

        $r = $p->preview($path);

        $this->assertSame(';', $r['delimiter']);
        $this->assertSame(['sku', 'title', 'price'], $r['headers']);
    }

    #[Test]
    public function it_auto_detects_tab_delimiter(): void
    {
        $path = $this->tempFile("sku\ttitle\tprice\nABC-1\tMerino sweater\t149.00\nABC-2\tCotton shirt\t29.00\n");
        $p = new CsvParser();

        $r = $p->preview($path);

        $this->assertSame("\t", $r['delimiter']);
        $this->assertSame(['sku', 'title', 'price'], $r['headers']);
    }

    #[Test]
    public function it_auto_detects_pipe_delimiter(): void
    {
        $path = $this->tempFile("sku|title|price\nABC-1|Merino sweater|149.00\nABC-2|Cotton shirt|29.00\n");
        $p = new CsvParser();

        $r = $p->preview($path);

        $this->assertSame('|', $r['delimiter']);
    }

    #[Test]
    public function it_strips_utf8_bom_from_header_row(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->tempFile($bom . "sku,title\nABC-1,Hello\n");
        $p = new CsvParser();

        $r = $p->preview($path);

        $this->assertSame('UTF-8-BOM', $r['encoding']);
        $this->assertSame(['sku', 'title'], $r['headers']);   // critical: no BOM on first header
    }

    #[Test]
    public function preview_caps_samples_at_limit_but_still_counts_every_row(): void
    {
        $rows = "sku,title\n";
        for ($i = 1; $i <= 50; $i++) {
            $rows .= "SKU-{$i},Product {$i}\n";
        }
        $path = $this->tempFile($rows);
        $p = new CsvParser();

        $r = $p->preview($path, 5);

        $this->assertCount(5, $r['samples']);
        $this->assertSame(50, $r['row_count']);
    }

    #[Test]
    public function it_throws_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open CSV');
        (new CsvParser())->preview('/nonexistent/path/file.csv');
    }

    #[Test]
    public function it_throws_on_empty_csv_with_no_header(): void
    {
        $path = $this->tempFile('');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no header row');
        (new CsvParser())->preview($path);
    }

    #[Test]
    public function rows_generator_streams_all_data_rows_with_header_keys(): void
    {
        $path = $this->tempFile("sku,title\nA,Alpha\nB,Beta\nC,Gamma\n");
        $p = new CsvParser();

        $rows = [];
        foreach ($p->rows($path) as $n => $row) {
            $rows[$n] = $row;
        }

        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertSame(['sku' => 'A', 'title' => 'Alpha'], $rows[1]);
        $this->assertSame(['sku' => 'C', 'title' => 'Gamma'], $rows[3]);
    }

    #[Test]
    public function rows_with_missing_trailing_cells_use_null(): void
    {
        // Second row has only one value for two-column header.
        $path = $this->tempFile("sku,title\nA,Alpha\nB\n");
        $p = new CsvParser();

        $rows = iterator_to_array($p->rows($path));

        $this->assertSame(['sku' => 'A', 'title' => 'Alpha'], $rows[1]);
        $this->assertSame(['sku' => 'B', 'title' => null], $rows[2]);
    }

    #[Test]
    public function headers_are_trimmed_of_whitespace(): void
    {
        $path = $this->tempFile("  sku  , title , price \nA,B,C\n");
        $p = new CsvParser();

        $this->assertSame(['sku', 'title', 'price'], $p->preview($path)['headers']);
    }

    #[Test]
    public function delimiter_detection_prefers_consistent_counts_across_lines(): void
    {
        // Column header has one semicolon in data but commas are the real delimiter.
        // Without the consistency heuristic a naive counter might pick the wrong char.
        $path = $this->tempFile("sku,title,notes\nA,Hello; world,note1\nB,Foo; bar,note2\n");
        $p = new CsvParser();

        $r = $p->preview($path);
        $this->assertSame(',', $r['delimiter']);
    }

    #[Test]
    public function windows_1252_encoding_is_reencoded_to_utf8_in_the_header(): void
    {
        // "café" encoded in CP1252: c-a-f-é (0xE9)
        $cp1252 = "sku,titl\xE9\nA,caf\xE9\n";
        $path = $this->tempFile($cp1252);
        $p = new CsvParser();

        $r = $p->preview($path);

        $this->assertSame('Windows-1252', $r['encoding']);
        // Header should be re-encoded to UTF-8 by the time it reaches us.
        $this->assertTrue(mb_check_encoding($r['headers'][1], 'UTF-8'));
        $this->assertStringContainsString('é', $r['headers'][1]);
    }
}
