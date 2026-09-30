<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Warnings;
use DOMDocument;
use DOMElement;
use DOMNode;
use Generator;
use LibXMLError;
use XMLReader;

/**
 * Streams XML feeds one item at a time (XMLReader + expand(), so a 1 GB file stays flat in memory)
 * and flattens each item:
 *
 *   <title>Hammer</title>                   title = Hammer
 *   <price currency="EUR">9.90</price>       price = 9.90, price@currency = EUR
 *   <shipping><price>4.95</price>            shipping.price = 4.95
 *   <image>a</image><image>b</image>         image[0] = a, image[1] = b
 *   <g:id>SKU-1</g:id>                       g:id = SKU-1 (the prefix as written)
 *   <description>Text <b>bold</b></description>   description = Text <b>bold</b> (mixed content)
 *
 * Fixes:
 * - D23: the legacy parser took the first depth-1 element as the item, so RSS feeds
 *   (rss/channel/item) broke. The item here is the element that repeats most under a parent that
 *   does not.
 * - D4: `g:` fields were dropped.
 *
 * Safety: no network access, no DTD loading, and a document declaring entities is refused
 * (XXE and billion laughs). A malformed document stops the import instead of being half read.
 */
final class XmlRowReader implements RowReader
{
    private const int DETECT_ELEMENTS = 50_000;

    private const int PROLOG_BYTES = 65_536;

    private readonly Warnings $warnings;

    public function __construct(
        private readonly string $path,
        private ?string $itemPath = null,
        private readonly int $maxDepth = 32,
    ) {
        $this->warnings = new Warnings;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** The slash-separated path of the item element, e.g. "rss/channel/item". */
    public function itemPath(): string
    {
        return $this->itemPath ??= $this->detectItemPath();
    }

    public function rows(): Generator
    {
        $this->refuseEntities();
        $itemPath = $this->itemPath();

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = $this->open();
        // Expanded items belong to this document, so their markup can be serialised.
        $document = new DOMDocument;
        $found = 0;

        try {
            $stack = [];
            $more = $this->quietly(static fn (): bool => $reader->read());
            while ($more) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    $more = $this->quietly(static fn (): bool => $reader->read());

                    continue;
                }

                $stack = array_slice($stack, 0, $reader->depth);
                $stack[] = $reader->name;
                if (implode('/', $stack) !== $itemPath) {
                    $more = $this->quietly(static fn (): bool => $reader->read());

                    continue;
                }

                $node = $this->quietly(static fn (): DOMNode|false => $reader->expand($document));
                if (! $node instanceof DOMElement) {
                    break;
                }

                $found++;
                $row = [];
                $this->flatten($node, '', $row, 0);
                yield $row;

                $more = $this->quietly(static fn (): bool => $reader->next());
            }

            $this->throwOnError();
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($found === 0) {
            throw UnreadableFeed::because(UnreadableReason::NoItems, "No <{$this->lastSegment($itemPath)}> items were found in the XML.");
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function flatten(DOMElement $element, string $prefix, array &$row, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw UnreadableFeed::because(UnreadableReason::TooDeep, "An item nests more than {$this->maxDepth} levels deep.");
        }

        foreach ($element->attributes as $attribute) {
            $row[$prefix.'@'.$attribute->nodeName] = (string) $attribute->nodeValue;
        }

        $groups = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $groups[$child->nodeName][] = $child;
            }
        }

        foreach ($groups as $name => $children) {
            $repeated = count($children) > 1;
            foreach ($children as $index => $child) {
                $key = ($prefix === '' ? $name : $prefix.'.'.$name).($repeated ? "[{$index}]" : '');

                if ($this->hasElementChildren($child) && ! $this->hasText($child)) {
                    $this->flatten($child, $key, $row, $depth + 1);

                    continue;
                }

                $text = $this->hasElementChildren($child) ? trim($this->innerXml($child)) : trim($child->textContent);
                if ($text !== '') {
                    $row[$key] = $text;
                }

                foreach ($child->attributes as $attribute) {
                    $row[$key.'@'.$attribute->nodeName] = (string) $attribute->nodeValue;
                }
            }
        }
    }

    private function hasElementChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /** Non-blank text directly inside the element: mixed content such as unescaped HTML. */
    private function hasText(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true) && trim((string) $child->nodeValue) !== '') {
                return true;
            }
        }

        return false;
    }

    private function innerXml(DOMNode $node): string
    {
        $xml = '';
        foreach ($node->childNodes as $child) {
            $xml .= (string) $node->ownerDocument?->saveXML($child);
        }

        return $xml;
    }

    /**
     * The item is the most repeated element among those whose parent does not repeat:
     * products/product, rss/channel/item and feed/entry qualify; product/images/image does not.
     * Without any repetition, a single item under the root.
     */
    private function detectItemPath(): string
    {
        $this->refuseEntities();
        $previous = libxml_use_internal_errors(true);
        $reader = $this->open();
        $counts = [];
        $first = [];
        $stack = [];
        $seen = 0;

        try {
            while ($seen < self::DETECT_ELEMENTS && $this->quietly(static fn (): bool => $reader->read())) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $seen++;
                $stack = array_slice($stack, 0, $reader->depth);
                $stack[] = $reader->name;
                $path = implode('/', $stack);
                $counts[$path] = ($counts[$path] ?? 0) + 1;
                $first[$path] ??= $seen;
            }

            if ($seen === 0) {
                $this->throwOnError();
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $best = null;
        foreach ($counts as $path => $count) {
            $parent = substr($path, 0, (int) strrpos($path, '/'));
            if ($count < 2 || ! str_contains($path, '/') || ($counts[$parent] ?? 1) > 1) {
                continue;
            }

            if ($best === null || $count > $counts[$best]) {
                $best = $path;
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach ($counts as $path => $count) {
            if (substr_count($path, '/') === 1) {
                return $path; // one item: the root's first child
            }
        }

        throw UnreadableFeed::because(UnreadableReason::NoItems, 'The XML has no items: its root element is empty.');
    }

    /** Entity declarations have no place in a product feed and are how XXE and billion-laughs attacks work. */
    private function refuseEntities(): void
    {
        $handle = fopen($this->path, 'rb');
        $prolog = $handle === false ? '' : (string) fread($handle, self::PROLOG_BYTES);
        if ($handle !== false) {
            fclose($handle);
        }

        $doctype = stripos($prolog, '<!DOCTYPE');
        if ($doctype === false) {
            return;
        }

        // Only an internal subset ("<!DOCTYPE x [ … ]>") can declare entities.
        $subset = strpos($prolog, '[', $doctype);
        $close = strpos($prolog, '>', $doctype);
        if ($subset === false || ($close !== false && $close < $subset)) {
            return;
        }

        $end = strpos($prolog, ']', $subset);
        if ($end === false || stripos(substr($prolog, $subset, $end - $subset), '<!ENTITY') !== false) {
            throw UnreadableFeed::because(UnreadableReason::XmlEntities, 'The XML declares entities, which FeedGenix does not read for safety. Export the feed without a DTD.');
        }
    }

    private function open(): XMLReader
    {
        $reader = new XMLReader;
        if (! $reader->open($this->path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES)) {
            throw UnreadableFeed::because(UnreadableReason::MalformedXml, 'The XML file cannot be opened.');
        }

        return $reader;
    }

    /**
     * XMLReader reports some parse failures as PHP warnings of its own ("An Error Occurred while
     * expanding"); libxml's error list says what and where. Either way the result is a refusal.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|false
     */
    private function quietly(callable $call): mixed
    {
        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        if ($failed) {
            $this->throwOnError();

            return false;
        }

        return $result;
    }

    private function throwOnError(): void
    {
        $fatal = array_values(array_filter(
            libxml_get_errors(),
            static fn (LibXMLError $error): bool => $error->level >= LIBXML_ERR_ERROR,
        ));

        if ($fatal !== []) {
            $error = $fatal[0];

            throw UnreadableFeed::because(UnreadableReason::MalformedXml, sprintf('The XML is not well-formed at line %d: %s', $error->line, trim($error->message)));
        }
    }

    private function lastSegment(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }
}
