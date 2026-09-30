# ADR 0012: Reading feeds

- Status: accepted
- Date: 2026-10-01

## Context
Customers bring feeds in whatever their platform exports: CSV with any delimiter and encoding,
Google Merchant RSS, custom XML, JSON, Excel, often gzipped or zipped, sometimes a login page behind
a URL. The legacy reader:
- trusted file names;
- never decompressed gzip;
- "detected" encodings without converting them;
- failed on duplicate headers;
- dropped namespaced XML fields and could not read RSS;
- loaded Excel files whole;
- skipped malformed XML silently.

Several legacy data-loss incidents started with half a file read as if it were whole.

## Decision

### Recognition
- **By content, never by name.** Magic bytes identify gzip, zip, XLSX, an old `.xls`, PDF, HTML and
  binary data. Anything that is not a feed is refused, with the reason.
- **Up to two containers** (zip, gzip) are removed. A zip's feed is streamed into a path FeedGenix
  chose, with its size and CRC-32 checked. Nothing is extracted by its archived name.

### Encoding
- **Every text feed is converted to UTF-8 before it is read**, streaming and chunk-safe.
- Detection: the BOM, the NUL pattern of BOM-less UTF-16, UTF-8 validity, the XML declaration, and
  otherwise Windows-1252.
- The customer can override the encoding from a fixed list of 15 encodings, converted by intl's
  UConverter.
- Invalid bytes become U+FFFD and are counted. NUL bytes are removed and counted.

### Formats
- **CSV/TSV:** RFC 4180 (the parser's backslash escape is off); the delimiter is detected from parsed
  records.
- **XML:** streamed item by item. The item is the most repeated element under a non-repeating parent.
  Namespace prefixes are kept as written (`g:id`). Documents declaring entities are refused, and
  nothing is fetched.
- **JSON / NDJSON:** streamed. Numbers keep their exact source text.
- **XLSX:** the first visible sheet, streamed. `.xls` is not supported: re-save it as XLSX or CSV.

### Values: the golden rule starts here
Values are kept exactly as written: no trimming, no reformatting, no parsing into numbers. The
exceptions are few, and each gives the exact text:
- XML text is trimmed of indentation.
- XLSX numbers are written in their shortest exact form.
- XLSX dates are written as ISO dates.
- XLSX booleans are written as TRUE/FALSE.

### Refusals and caps
- **Never partial.** A malformed file, a truncated archive or a broken cap stops the import with a
  typed reason (`UnreadableReason`) and a message that says what to change. Nothing downstream ever
  sees part of a catalogue.
- **Caps**, configured in `config/ingest.php`, each named in its refusal:
  - bytes received;
  - inflated bytes and inflate ratio;
  - rows;
  - columns;
  - bytes per value;
  - nesting depth.

### Profiling
A profile is built in one pass:
- per column: fill rate, value kind, examples and the longest length;
- sample rows;
- a column signature;
- a SKU suggestion chosen on evidence (filled and unique), not on the column name alone.

## Consequences
- Customers with `.xls` files must re-save them. The message says so.
- Composing columns (the legacy Competec joiners) moves to mapping (M3), where every feed benefits.
- Reading is strict. A feed that was "mostly readable" before now fails with a reason, which the
  import screen shows along with the fix.
