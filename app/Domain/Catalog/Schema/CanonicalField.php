<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

/** One field of the canonical schema (config/canonical_schema.php). */
final readonly class CanonicalField
{
    /** @param list<string> $hints column names that usually carry it, lower case */
    public function __construct(
        public string $key,
        public FieldClass $class,
        public FieldKind $kind,
        public string $label,
        public string $description,
        public ?TranslationMode $defaultMode,
        public array $hints,
    ) {}

    public function isGolden(): bool
    {
        return $this->class->isGolden();
    }

    public function isLocalized(): bool
    {
        return $this->class->isLocalized();
    }

    public function isComposable(): bool
    {
        return $this->class === FieldClass::Composable;
    }
}
