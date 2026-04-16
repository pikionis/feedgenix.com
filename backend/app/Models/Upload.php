<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Upload extends Model
{
    use HasFactory;

    /**
     * Indicates that the model does not have an "updated_at" timestamp.
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'filename',
        'file_path',
        'file_hash',
        'file_size',
        'row_count',
        'column_count',
        'column_names',
        'sample_rows',
        'status',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_names' => 'array',
            'sample_rows' => 'array',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'column_count' => 'integer',
        ];
    }

    /**
     * Get the project that owns the upload.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the field mapping associated with the upload.
     */
    public function fieldMapping(): HasOne
    {
        return $this->hasOne(FieldMapping::class);
    }
}
