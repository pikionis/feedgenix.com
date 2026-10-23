<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt that reached a provider (ADR 0004), written by the Recorder only. cost_usd is internal
 * and never leaves the server (ADR 0005).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $reservation_id
 * @property string $purpose
 * @property string $provider
 * @property string $model
 * @property string $prompt name@version
 * @property int $attempt
 * @property int $item_count
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $thinking_tokens
 * @property int $cached_input_tokens
 * @property int $latency_ms
 * @property string $status ok, retryable or terminal
 * @property string|null $error_code
 * @property string $cost_usd
 * @property int $millicredits
 * @property int $unbilled_millicredits
 * @property CarbonImmutable $created_at
 */
#[Hidden(['cost_usd'])]
class EngineCall extends Model
{
    use BelongsToTenant;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'reservation_id' => 'integer',
            'attempt' => 'integer',
            'item_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'latency_ms' => 'integer',
            'millicredits' => 'integer',
            'unbilled_millicredits' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
