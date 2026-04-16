<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'description',
        'source_language',
        'target_languages',
        'status',
        'products_count',
        'last_upload_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_languages' => 'array',
            'last_upload_at' => 'datetime',
            'products_count' => 'integer',
        ];
    }

    /**
     * Get the team that owns the project.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get all uploads for the project.
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    /**
     * Get all field mappings for the project.
     */
    public function fieldMappings(): HasMany
    {
        return $this->hasMany(FieldMapping::class);
    }

    /**
     * Get all products for the project.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all translation jobs for the project.
     */
    public function translationJobs(): HasMany
    {
        return $this->hasMany(TranslationJob::class);
    }

    /**
     * Get the latest field mapping for the project.
     */
    public function latestMapping(): HasOne
    {
        return $this->hasOne(FieldMapping::class)->latestOfMany();
    }
}
