<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One column of an import's profile.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $import_id
 * @property int $position
 * @property string $name
 * @property int $filled
 * @property string $fill_rate
 * @property int $max_length
 * @property string $kind
 * @property list<string> $examples
 */
#[Fillable(['import_id', 'position', 'name', 'filled', 'fill_rate', 'max_length', 'kind', 'examples'])]
#[WithoutTimestamps]
class ImportColumn extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'import_id' => 'integer',
            'position' => 'integer',
            'filled' => 'integer',
            'max_length' => 'integer',
            'examples' => 'array',
        ];
    }
}
