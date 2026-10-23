<?php

declare(strict_types=1);

namespace App\Domain\Export\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One build of a profile (ADR 0007). Its files are written once under their own storage key and
 * never changed; publishing moves the profile's pointer to it. A build that fails its checks or
 * the shrink guard is kept as a record and never served.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $profile_id
 * @property string $status building, published, superseded, unchanged, rejected or failed
 * @property string|null $storage_key
 * @property list<array{name: string, locale: string|null, rows: int, bytes: int, sha256: string, gz_bytes: int, gz_sha256: string}> $files
 * @property int $rows
 * @property string|null $content_hash
 * @property array<string, mixed> $report
 * @property string|null $failure_reason
 * @property string|null $failure_message
 * @property bool $files_deleted
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property-read ExportProfile $profile
 */
class ExportArtifact extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'building',
        'storage_key' => null,
        'files' => '[]',
        'rows' => 0,
        'content_hash' => null,
        'report' => '{}',
        'failure_reason' => null,
        'failure_message' => null,
        'files_deleted' => false,
        'published_at' => null,
    ];

    /**
     * @return BelongsTo<ExportProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ExportProfile::class, 'profile_id');
    }

    /** @return array{name: string, locale: string|null, rows: int, bytes: int, sha256: string, gz_bytes: int, gz_sha256: string}|null */
    public function file(string $name): ?array
    {
        foreach ($this->files as $file) {
            if ($file['name'] === $name) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'profile_id' => 'integer',
            'files' => 'array',
            'rows' => 'integer',
            'report' => 'array',
            'files_deleted' => 'boolean',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
