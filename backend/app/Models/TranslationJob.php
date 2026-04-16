<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationJob extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'team_id',
        'status',
        'total_products',
        'processed_products',
        'total_translations',
        'completed_translations',
        'cached_translations',
        'failed_translations',
        'estimated_cost',
        'actual_cost',
        'started_at',
        'completed_at',
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
            'total_products' => 'integer',
            'processed_products' => 'integer',
            'total_translations' => 'integer',
            'completed_translations' => 'integer',
            'cached_translations' => 'integer',
            'failed_translations' => 'integer',
            'estimated_cost' => 'float',
            'actual_cost' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the project that owns the translation job.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the team that owns the translation job.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
