<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Encoding;

/**
 * The encodings FeedGenix converts from, by the name customers see, mapped to intl's converter.
 * ICU's canonical names avoid its "ambiguous alias" warning for the Windows code pages (and
 * mbstring cannot do Windows-1250 or -1253 at all).
 */
final class Encodings
{
    public const string UTF8 = 'UTF-8';

    public const string FALLBACK = 'Windows-1252';

    /** @var array<string, string> name shown and stored => ICU converter */
    private const array CONVERTERS = [
        'UTF-8' => 'UTF-8',
        'UTF-16LE' => 'UTF-16LE',
        'UTF-16BE' => 'UTF-16BE',
        'UTF-32LE' => 'UTF-32LE',
        'UTF-32BE' => 'UTF-32BE',
        'Windows-1252' => 'ibm-5348_P100-1997', // Western Europe: Excel "ANSI" on English, French, German… Windows
        'ISO-8859-1' => 'ISO-8859-1',
        'ISO-8859-15' => 'ISO-8859-15',
        'Windows-1250' => 'ibm-5346_P100-1998', // Central Europe: Polish, Czech, Hungarian…
        'ISO-8859-2' => 'ISO-8859-2',
        'Windows-1251' => 'ibm-5347_P100-1998', // Cyrillic
        'Windows-1253' => 'ibm-5349_P100-1998', // Greek
        'ISO-8859-7' => 'ISO-8859-7',
        'Windows-1254' => 'ibm-5350_P100-1998', // Turkish
        'ISO-8859-9' => 'ISO-8859-9',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CONVERTERS);
    }

    /** The stored name for any spelling ("utf8", "windows-1252", "latin1" → …), or null. */
    public static function canonical(string $name): ?string
    {
        $key = strtolower(str_replace(['_', ' '], '-', trim($name)));
        $aliases = ['utf8' => 'UTF-8', 'latin1' => 'ISO-8859-1', 'latin-1' => 'ISO-8859-1', 'cp1252' => 'Windows-1252',
            'cp1250' => 'Windows-1250', 'cp1251' => 'Windows-1251', 'cp1253' => 'Windows-1253', 'cp1254' => 'Windows-1254',
            'ascii' => 'UTF-8', 'us-ascii' => 'UTF-8'];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        foreach (array_keys(self::CONVERTERS) as $canonical) {
            if (strtolower($canonical) === $key) {
                return $canonical;
            }
        }

        return null;
    }

    public static function isSupported(string $name): bool
    {
        return self::canonical($name) !== null;
    }

    public static function converter(string $name): string
    {
        return self::CONVERTERS[self::canonical($name) ?? self::UTF8];
    }

    /** Bytes per code unit: conversion chunks must end on a unit boundary. */
    public static function unitBytes(string $name): int
    {
        return match (self::canonical($name)) {
            'UTF-16LE', 'UTF-16BE' => 2,
            'UTF-32LE', 'UTF-32BE' => 4,
            default => 1,
        };
    }
}
