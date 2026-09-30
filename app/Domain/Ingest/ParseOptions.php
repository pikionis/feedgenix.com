<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Ingest\Encoding\Encodings;
use InvalidArgumentException;

/**
 * What the customer may override when detection guessed wrong. Null means "detect".
 */
final readonly class ParseOptions
{
    public const array DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(
        public ?string $encoding = null,
        public ?string $delimiter = null,
        public bool $quoted = true,
        public ?string $xmlItemPath = null,
    ) {
        if ($encoding !== null && ! Encodings::isSupported($encoding)) {
            throw new InvalidArgumentException("Unsupported encoding [{$encoding}].");
        }

        if ($delimiter !== null && ! in_array($delimiter, self::DELIMITERS, true)) {
            throw new InvalidArgumentException('The delimiter must be a comma, semicolon, tab or pipe.');
        }
    }

    /** @param array<string, mixed> $options as stored on a feed */
    public static function fromArray(array $options): self
    {
        $string = static fn (string $key): ?string => isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : null;

        return new self(
            encoding: $string('encoding'),
            delimiter: $string('delimiter'),
            quoted: ($options['quoted'] ?? true) !== false,
            xmlItemPath: $string('xml_item_path'),
        );
    }

    /** @return array{encoding: ?string, delimiter: ?string, quoted: bool, xml_item_path: ?string} */
    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding,
            'delimiter' => $this->delimiter,
            'quoted' => $this->quoted,
            'xml_item_path' => $this->xmlItemPath,
        ];
    }
}
