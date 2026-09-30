<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Encoding;

/**
 * The encoding a text feed will be read as, and why (shown to the customer next to an override).
 *
 * Reasons: bom, utf16_pattern, valid_utf8, ascii, xml_declaration, fallback (the bytes are not
 * UTF-8, so Windows-1252 is assumed), override (the customer chose it).
 */
final readonly class DetectedEncoding
{
    public function __construct(
        public string $name,
        public string $reason,
        public int $bomBytes = 0,
    ) {}

    public function withOverride(string $name): self
    {
        return new self(Encodings::canonical($name) ?? $name, 'override', $this->bomBytes);
    }

    public function isUtf8(): bool
    {
        return $this->name === Encodings::UTF8;
    }
}
