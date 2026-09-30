<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\Readers\XmlRowReader;

function refusal(callable $read): UnreadableReason
{
    try {
        $read();
    } catch (UnreadableFeed $exception) {
        return $exception->reason;
    }

    throw new RuntimeException('expected the feed to be refused');
}

it('yields one row per product with leaves, attributes, nesting and repeats (legacy spec)', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
  <product id="p1">
    <sku>A-1</sku>
    <price currency="EUR" vat="incl">19.90</price>
    <pricing><retail>49.99</retail><wholesale>22.00</wholesale></pricing>
    <Categories>
      <Category id="L1">Electronics</Category>
      <Category id="L2">Networking</Category>
    </Categories>
    <subtitle></subtitle>
    <weight/>
    <EnergyLabel rating="A+" />
  </product>
  <product id="p2"><sku>A-2</sku></product>
</products>
XML;

    expect(readRows($xml))->toBe([
        [
            '@id' => 'p1',
            'sku' => 'A-1',
            'price' => '19.90',
            'price@currency' => 'EUR',
            'price@vat' => 'incl',
            'pricing.retail' => '49.99',
            'pricing.wholesale' => '22.00',
            'Categories.Category[0]' => 'Electronics',
            'Categories.Category[0]@id' => 'L1',
            'Categories.Category[1]' => 'Networking',
            'Categories.Category[1]@id' => 'L2',
            'EnergyLabel@rating' => 'A+',
        ],
        ['@id' => 'p2', 'sku' => 'A-2'],
    ]);
});

it('reads a Google Merchant RSS feed: items under rss/channel (D23) with their g: fields (D4)', function (): void {
    $xml = <<<'XML'
<?xml version="1.0"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <title>Shop</title>
    <link>https://shop.example</link>
    <item>
      <g:id>SKU-1</g:id>
      <title>Hammer</title>
      <g:price>19.90 EUR</g:price>
      <g:gtin>4006381333931</g:gtin>
      <g:shipping><g:country>DE</g:country><g:price>4.95 EUR</g:price></g:shipping>
      <g:additional_image_link>https://img.example/1.jpg</g:additional_image_link>
      <g:additional_image_link>https://img.example/2.jpg</g:additional_image_link>
    </item>
    <item><g:id>SKU-2</g:id><title>Nail</title></item>
  </channel>
</rss>
XML;

    $path = feedFile($xml);
    $rows = readFeed($path)['rows'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'g:id' => 'SKU-1',
            'title' => 'Hammer',
            'g:price' => '19.90 EUR',
            'g:gtin' => '4006381333931',
            'g:shipping.g:country' => 'DE',
            'g:shipping.g:price' => '4.95 EUR',
            'g:additional_image_link[0]' => 'https://img.example/1.jpg',
            'g:additional_image_link[1]' => 'https://img.example/2.jpg',
        ])
        ->and((new XmlRowReader($path))->itemPath())->toBe('rss/channel/item');
});

it('reads an Atom feed with a default namespace', function (): void {
    $xml = '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0"><title>Shop</title>'
        .'<entry><g:id>1</g:id><title>A</title></entry><entry><g:id>2</g:id><title>B</title></entry></feed>';

    expect(readRows($xml))->toBe([['g:id' => '1', 'title' => 'A'], ['g:id' => '2', 'title' => 'B']]);
});

it('finds the items even when other elements repeat before them', function (): void {
    $xml = '<catalog><category>A</category><category>B</category><products>'
        .'<product><sku>1</sku><image>a</image><image>b</image><image>c</image></product>'
        .'<product><sku>2</sku></product><product><sku>3</sku></product></products></catalog>';

    expect(array_column(readRows($xml), 'sku'))->toBe(['1', '2', '3']);
});

it('reads a feed with a single product', function (): void {
    expect(readRows('<products><product><sku>ONLY</sku></product></products>'))->toBe([['sku' => 'ONLY']]);
});

it('uses the item path the customer chose', function (): void {
    $xml = '<catalog><category><name>A</name></category><category><name>B</name></category><products><product><sku>1</sku></product></products></catalog>';

    expect(readRows($xml, new ParseOptions(xmlItemPath: 'catalog/products/product')))->toBe([['sku' => '1']]);
});

it('keeps HTML from CDATA, escaped markup and unescaped mixed content', function (): void {
    $xml = '<products><product>'
        .'<a><![CDATA[<p>Soft <b>cotton</b></p>]]></a>'
        .'<b>&lt;p&gt;Escaped&lt;/p&gt;</b>'
        .'<c>Plain <b>bold</b> text</c>'
        .'</product></products>';

    expect(readRows($xml))->toBe([[
        'a' => '<p>Soft <b>cotton</b></p>',
        'b' => '<p>Escaped</p>',
        'c' => 'Plain <b>bold</b> text',
    ]]);
});

it('reads XML declared as ISO-8859-1 and converts it once, not twice', function (): void {
    $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n<products><product><title>Cr\xE8me br\xFBl\xE9e</title></product></products>";

    $read = readFeed(feedFile($xml));

    expect($read['rows'])->toBe([['title' => 'Crème brûlée']])
        ->and($read['feed']->encoding?->reason)->toBe('xml_declaration');
});

it('reads XML 1.1, which libxml cannot parse as such', function (): void {
    expect(readRows('<?xml version="1.1" encoding="UTF-8"?><products><product><sku>1</sku></product></products>'))->toBe([['sku' => '1']]);
});

it('refuses entity declarations: billion laughs', function (): void {
    $xml = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;">]>'
        .'<products><product><title>&lol2;</title></product></products>';

    expect(refusal(fn (): array => readRows($xml)))->toBe(UnreadableReason::XmlEntities);
});

it('refuses entity declarations: external entities (XXE)', function (): void {
    $xml = '<?xml version="1.0"?><!DOCTYPE p [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
        .'<products><product><title>&secret;</title></product></products>';

    expect(refusal(fn (): array => readRows($xml)))->toBe(UnreadableReason::XmlEntities);
});

it('never fetches an external DTD and still reads the feed', function (): void {
    $xml = '<?xml version="1.0"?><!DOCTYPE products SYSTEM "http://127.0.0.1:1/never.dtd"><products><product><sku>1</sku></product></products>';

    expect(readRows($xml))->toBe([['sku' => '1']]);
});

it('does not mistake "<!ENTITY" inside product text for a declaration', function (): void {
    expect(readRows('<products><product><note><![CDATA[use <!ENTITY> carefully]]></note></product></products>'))
        ->toBe([['note' => 'use <!ENTITY> carefully']]);
});

it('stops with the line number on malformed XML instead of importing half a catalogue', function (): void {
    $xml = "<products>\n<product><sku>1</sku></product>\n<product><sku>2</sku><name>BROKEN&unclosed</name></product>\n<product><sku>3</sku></product>\n</products>";

    try {
        readRows($xml);
        $this->fail('expected a refusal');
    } catch (UnreadableFeed $exception) {
        expect($exception->reason)->toBe(UnreadableReason::MalformedXml)
            ->and($exception->getMessage())->toContain('line 3');
    }
});

it('refuses an XML document without items', function (): void {
    expect(refusal(fn (): array => readRows('<products></products>')))->toBe(UnreadableReason::NoItems);
});

it('refuses items nested deeper than the cap', function (): void {
    $deep = str_repeat('<n>', 40).'x'.str_repeat('</n>', 40);

    expect(refusal(fn (): array => readRows("<products><product>{$deep}</product><product><sku>2</sku></product></products>")))
        ->toBe(UnreadableReason::TooDeep);
});
