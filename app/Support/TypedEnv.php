<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Env;
use InvalidArgumentException;

/**
 * Typed access to environment variables for config files.
 *
 * Laravel's env() turns "true", "false", "null" and "empty" into bool/null/''. A string
 * setting such as APP_URL=true would then reach rtrim()/explode() as a bool. Fail fast with
 * a clear message instead of building a wrong URL or domain list.
 */
final class TypedEnv
{
    public static function string(string $key, string $default): string
    {
        $value = Env::get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Environment variable [%s] must be a string, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /** true/false (or 1/0), empty for the default; anything else is a typo that must not silently mean "off". */
    public static function bool(string $key, bool $default): bool
    {
        $value = Env::get($key, $default);

        return match (true) {
            is_bool($value) => $value,
            $value === '' => $default,
            $value === '1' => true,
            $value === '0' => false,
            default => throw new InvalidArgumentException(sprintf(
                'Environment variable [%s] must be true or false, got "%s".',
                $key,
                is_scalar($value) ? (string) $value : get_debug_type($value),
            )),
        };
    }

    /** A positive whole number written in digits, empty for the default; "2GB" or "-1" fail fast. */
    public static function positiveInt(string $key, int $default): int
    {
        $value = Env::get($key, $default);

        return match (true) {
            is_int($value) => $value,
            $value === '' => $default,
            is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1 => (int) $value,
            default => throw new InvalidArgumentException(sprintf(
                'Environment variable [%s] must be a positive whole number, got "%s".',
                $key,
                is_scalar($value) ? (string) $value : get_debug_type($value),
            )),
        };
    }
}
