<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
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
        'product_id',
        'field_name',
        'source_text',
        'source_hash',
        'target_language',
        'translated_text',
        'provider',
        'quality_score',
        'is_cached',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quality_score' => 'float',
            'is_cached' => 'boolean',
        ];
    }

    /**
     * Get the product that owns the translation.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
