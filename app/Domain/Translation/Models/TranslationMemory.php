<?php

declare(strict_types=1);

namespace App\Domain\Translation\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A translation that passed the quality checks, reused at no cost for the same source text in the
 * same field and languages. Only checked translations are kept (the legacy memory stored every
 * answer before checking it, and served the bad ones for ever).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $field
 * @property string $source_locale
 * @property string $target_locale
 * @property string $source_hash
 * @property string $value
 * @property string|null $prompt
 * @property string|null $model
 * @property int $hits
 * @property CarbonImmutable|null $last_used_at
 */
#[Table(name: 'translation_memory')]
class TranslationMemory extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'prompt' => null,
        'model' => null,
        'hits' => 0,
        'last_used_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'hits' => 'integer',
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
