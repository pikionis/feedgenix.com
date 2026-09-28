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
}
