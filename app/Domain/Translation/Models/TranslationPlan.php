<?php

declare(strict_types=1);

namespace App\Domain\Translation\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a feed translates, and into which languages: per text field "translate", "keep" (the source
 * text as it is, in every language) or "skip" (not published in other languages).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property array<string, string> $fields field => translate, keep or skip
 * @property list<string> $target_locales
 * @property int $version
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 */
class TranslationPlan extends Model
{
    use BelongsToTenant;

    public const array MODES = ['translate', 'keep', 'skip'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'fields' => '{}',
        'target_locales' => '[]',
        'version' => 1,
        'updated_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'fields' => 'array',
            'target_locales' => 'array',
            'version' => 'integer',
            'updated_by' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
