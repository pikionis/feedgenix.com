<?php

declare(strict_types=1);

namespace App\Domain\Translation\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A language from the registry (config/languages.php), keyed by its BCP-47 code.
 *
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string $direction
 * @property bool $active
 */
#[WithoutIncrementing]
#[WithoutTimestamps]
class Language extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
