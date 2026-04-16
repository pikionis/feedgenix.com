<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'external_id',
        'row_number',
        'source_data',
        'content_hash',
        'is_new',
        'has_changes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'is_new' => 'boolean',
            'has_changes' => 'boolean',
            'row_number' => 'integer',
        ];
    }

    /**
     * Get the project that owns the product.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all translations for the product.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
