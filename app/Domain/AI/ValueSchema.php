<?php

declare(strict_types=1);

namespace App\Domain\AI;

/**
 * Checks one answer against its item's value schema, whatever the provider promised: a provider's
 * schema mode may ignore keywords, and a fake or a future host may not constrain at all. The subset
 * the gateway's schemas use: type (one or a list, null included), properties, required,
 * additionalProperties: false, enum, items, minItems/maxItems, maxLength.
 */
final class ValueSchema
{
    /** @param array<array-key, mixed> $schema */
    public static function accepts(array $schema, mixed $value): bool
    {
        $types = isset($schema['type']) ? array_values((array) $schema['type']) : [];
        if ($types !== [] && ! self::hasType($types, $value)) {
            return false;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (is_string($value) && isset($schema['maxLength']) && is_int($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            return false;
        }

        if (! is_array($value)) {
            return true;
        }
        // [] is both an empty list and an empty object: the schema's type decides.
        if (array_is_list($value) && ($value !== [] || ! in_array('object', $types, true))) {
            return self::acceptsList($schema, $value);
        }

        return self::acceptsObject($schema, $value);
    }

    /** @param list<mixed> $types */
    private static function hasType(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  array<array-key, mixed>  $value
     */
    private static function acceptsObject(array $schema, array $value): bool
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $value)) {
                return false;
            }
        }
        foreach ($value as $key => $property) {
            if (! array_key_exists($key, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }

                continue;
            }
            if (is_array($properties[$key]) && ! self::accepts($properties[$key], $property)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  list<mixed>  $value
     */
    private static function acceptsList(array $schema, array $value): bool
    {
        if (isset($schema['minItems']) && is_int($schema['minItems']) && count($value) < $schema['minItems']) {
            return false;
        }
        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && count($value) > $schema['maxItems']) {
            return false;
        }
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            foreach ($value as $item) {
                if (! self::accepts($items, $item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
