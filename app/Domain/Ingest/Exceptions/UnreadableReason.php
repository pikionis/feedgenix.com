<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Exceptions;

/** Why a feed could not be read. The API returns the value; the app words it for the customer. */
enum UnreadableReason: string
{
    case Empty = 'empty';
    case TooLarge = 'too_large';
    case UnsupportedFormat = 'unsupported_format';
    case LegacyExcel = 'legacy_excel';
    case TruncatedArchive = 'truncated_archive';
    case CorruptArchive = 'corrupt_archive';
    case DecompressionBomb = 'decompression_bomb';
    case EncryptedArchive = 'encrypted_archive';
    case NoFeedInArchive = 'no_feed_in_archive';
    case UnknownEncoding = 'unknown_encoding';
    case MalformedXml = 'malformed_xml';
    case XmlEntities = 'xml_entities';
    case MalformedJson = 'malformed_json';
    case NoItems = 'no_items';
    case NoHeader = 'no_header';
    case TooManyRows = 'too_many_rows';
    case TooManyColumns = 'too_many_columns';
    case CellTooLarge = 'cell_too_large';
    case TooDeep = 'too_deep';
}
