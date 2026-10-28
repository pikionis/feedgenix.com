<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Translation\Models\Language;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A target language a workspace translates into.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $language_code
 * @property CarbonImmutable|null $plan_paused_at over the plan's limit: no new translations into it
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['language_code'])]
class TenantLanguage extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'plan_paused_at' => null,
    ];

    /** @return list<string> the workspace's languages that are not paused by its plan */
    public static function activeCodes(): array
    {
        return array_values(array_map(strval(...), self::query()->whereNull('plan_paused_at')->orderBy('language_code')->pluck('language_code')->all()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'plan_paused_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_code', 'code');
    }
}
