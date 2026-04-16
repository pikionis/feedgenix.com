<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldMapping extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'upload_id',
        'mapping',
        'translatable_fields',
        'protected_fields',
        'skipped_fields',
        'ambiguous_fields',
        'detected_source_language',
        'ai_confidence',
        'ai_model',
        'user_approved',
        'approved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'translatable_fields' => 'array',
            'protected_fields' => 'array',
            'skipped_fields' => 'array',
            'ambiguous_fields' => 'array',
            'ai_confidence' => 'float',
            'user_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Get the project that owns the field mapping.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the upload that this mapping was generated from.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
