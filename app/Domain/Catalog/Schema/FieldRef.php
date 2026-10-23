<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

use InvalidArgumentException;

/**
 * A canonical field in a language: `title@de-CH`. Localized fields always carry a BCP-47 locale from
 * the registry (config/languages.php); golden, pass-through and derived fields never do.
 */
final readonly class FieldRef
{
    private function __construct(
        public string $field,
        public ?string $locale,
    ) {}

    public static function of(CanonicalSchema $schema, string $field, ?string $locale = null): self
    {
        $definition = $schema->get($field);
        if ($definition->isLocalized() && $locale === null) {
            throw new InvalidArgumentException("Field [{$field}] is stored per language: name its locale ({$field}@de).");
        }
        if (! $definition->isLocalized() && $locale !== null) {
            throw new InvalidArgumentException("Field [{$field}] is the same in every language: it takes no locale.");
        }

        return new self($field, $locale === null ? null : self::locale($locale));
    }

    public static function parse(CanonicalSchema $schema, string $ref): self
    {
        $parts = explode('@', trim($ref), 2);

        return self::of($schema, $parts[0], $parts[1] ?? null);
    }

    /** The registry's spelling of a BCP-47 code, matched case-insensitively ("DE-ch" → "de-CH"). */
    public static function locale(string $code): string
    {
        $wanted = strtolower(str_replace('_', '-', trim($code)));
        foreach (array_keys((array) config('languages', [])) as $known) {
            if (strtolower((string) $known) === $wanted) {
                return (string) $known;
            }
        }

        throw new InvalidArgumentException("[{$code}] is not a language in the registry.");
    }

    public function toString(): string
    {
        return $this->locale === null ? $this->field : "{$this->field}@{$this->locale}";
    }
}
