<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

use InvalidArgumentException;

/**
 * The canonical product schema (ADR 0003), read from config/canonical_schema.php and checked once:
 * a misconfigured field fails at boot of the first use, not as a wrong mapping.
 */
final readonly class CanonicalSchema
{
    /** @param array<string, CanonicalField> $fields */
    private function __construct(private array $fields) {}

    public static function fromConfig(): self
    {
        $fields = [];
        foreach ((array) config('canonical_schema.fields', []) as $key => $entry) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key) !== 1) {
                throw new InvalidArgumentException("Canonical field key [{$key}] must be lower snake case.");
            }
            $entry = (array) $entry;
            $class = FieldClass::tryFrom((string) ($entry['class'] ?? '')) ?? throw new InvalidArgumentException("Field [{$key}] has an unknown class.");
            $kind = FieldKind::tryFrom((string) ($entry['kind'] ?? '')) ?? throw new InvalidArgumentException("Field [{$key}] has an unknown kind.");
            $mode = isset($entry['mode']) ? TranslationMode::tryFrom((string) $entry['mode']) : null;
            if ($class->isLocalized() !== ($mode instanceof TranslationMode)) {
                throw new InvalidArgumentException("Field [{$key}]: localized fields need a mode, the others must not have one.");
            }
            $hints = array_values(array_map(static fn (mixed $hint): string => mb_strtolower(trim((string) $hint)), (array) ($entry['hints'] ?? [])));

            $fields[$key] = new CanonicalField($key, $class, $kind, (string) ($entry['label'] ?? $key), (string) ($entry['description'] ?? ''), $mode, $hints);
        }
        if ($fields === []) {
            throw new InvalidArgumentException('The canonical schema has no fields.');
        }

        return new self($fields);
    }

    /** @return array<string, CanonicalField> */
    public function all(): array
    {
        return $this->fields;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function get(string $key): CanonicalField
    {
        return $this->fields[$key] ?? throw new InvalidArgumentException("There is no canonical field [{$key}].");
    }

    public function isGolden(string $key): bool
    {
        return isset($this->fields[$key]) && $this->fields[$key]->isGolden();
    }

    /** @return array<string, CanonicalField> */
    public function ofClass(FieldClass ...$classes): array
    {
        return array_filter($this->fields, static fn (CanonicalField $field): bool => in_array($field->class, $classes, true));
    }

    /** @return array<string, CanonicalField> fields a feed column can be mapped to */
    public function mappable(): array
    {
        return array_filter($this->fields, static fn (CanonicalField $field): bool => $field->class->isMappable());
    }

    /** @return list<string> */
    public function goldenKeys(): array
    {
        return array_keys($this->ofClass(FieldClass::Golden));
    }
}
