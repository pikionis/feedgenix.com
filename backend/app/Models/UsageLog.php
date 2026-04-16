<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
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
        'team_id',
        'action',
        'quantity',
        'metadata',
        'billing_period',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'quantity' => 'integer',
        ];
    }

    /**
     * Get the team that owns the usage log.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
