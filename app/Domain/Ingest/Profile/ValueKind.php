<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

/**
 * What a column's values look like, from a sample of them. Mapping (M3) uses it as evidence; it
 * never changes a value.
 */
enum ValueKind: string
{
    case Empty = 'empty';
    case Gtin = 'gtin';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case ImageUrl = 'image_url';
    case Url = 'url';
    case Html = 'html';
    case Code = 'code';
    case Text = 'text';

    /** In order of specificity: the first kind nearly all values share wins. */
    public static function classify(string $value): self
    {
        return match (true) {
            self::isGtin($value) => self::Gtin,
            preg_match('/^-?(?:0|[1-9]\d*)$/', $value) === 1 => self::Integer,
            // No leading zeros: "0012345" is a code, and must never be treated as a number.
            preg_match('/^-?(?:0|[1-9]\d*|[1-9]\d{0,2}(?:[.,\x{2009} ]\d{3})+)(?:[.,]\d+)?$/u', $value) === 1 => self::Decimal,
            preg_match('/^(?:true|false|yes|no)$/i', $value) === 1 => self::Boolean,
            preg_match('/^(?:\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?|\d{1,2}[.\/]\d{1,2}[.\/]\d{4})/', $value) === 1 => self::Date,
            preg_match('~^https?://\S+\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?|svg)(?:\?\S*)?$~i', $value) === 1 => self::ImageUrl,
            preg_match('~^https?://\S+$~i', $value) === 1 => self::Url,
            preg_match('~</?[a-z][a-z0-9]*[\s>/]~i', $value) === 1 => self::Html,
            preg_match('~^(?=.*\d)[A-Za-z0-9][A-Za-z0-9._/-]{0,63}$~', $value) === 1 => self::Code,
            default => self::Text,
        };
    }

    /** 8, 12, 13 or 14 digits with a valid GS1 check digit. */
    public static function isGtin(string $value): bool
    {
        $length = strlen($value);
        if (! in_array($length, [8, 12, 13, 14], true) || ! ctype_digit($value)) {
            return false;
        }

        $sum = 0;
        for ($i = $length - 2, $weight = 3; $i >= 0; $i--, $weight = 4 - $weight) {
            $sum += (int) $value[$i] * $weight;
        }

        return (10 - $sum % 10) % 10 === (int) $value[$length - 1];
    }
}
