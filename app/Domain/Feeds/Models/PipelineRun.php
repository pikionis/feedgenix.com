<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a feed through the pipeline (ADR 0006): sync → plan [awaiting a decision] →
 * translate → export. One unfinished run per feed, enforced by a partial unique index.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property int|null $import_id
 * @property int|null $mapping_id
 * @property string $stage sync, plan, translate, export or done
 * @property string $status queued, running, awaiting_decision, held, done or failed
 * @property array<string, int> $counts
 * @property list<array{code: string, message: string, count: int}> $warnings
 * @property string|null $failure_reason
 * @property string|null $failure_message
 * @property int $attempt
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property-read Feed $feed
 */
class PipelineRun extends Model
{
    use BelongsToTenant;

    public const array UNFINISHED = ['queued', 'running', 'awaiting_decision', 'held'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'counts' => '{}',
        'warnings' => '[]',
        'failure_reason' => null,
        'failure_message' => null,
        'attempt' => 1,
        'import_id' => null,
        'mapping_id' => null,
        'started_at' => null,
        'finished_at' => null,
    ];

    /**
     * @return BelongsTo<Feed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    public function isUnfinished(): bool
    {
        return in_array($this->status, self::UNFINISHED, true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'import_id' => 'integer',
            'mapping_id' => 'integer',
            'counts' => 'array',
            'warnings' => 'array',
            'attempt' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
