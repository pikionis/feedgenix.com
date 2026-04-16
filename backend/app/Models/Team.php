<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Team extends Model
{
    use Billable, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'owner_id',
        'slug',
        'stripe_customer_id',
        'plan',
        'products_used_this_month',
        'billing_cycle_reset_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_cycle_reset_at' => 'datetime',
            'products_used_this_month' => 'integer',
        ];
    }

    /**
     * Get the owner of the team.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all members of the team.
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all projects belonging to the team.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get all translation jobs for the team.
     */
    public function translationJobs(): HasMany
    {
        return $this->hasMany(TranslationJob::class);
    }

    /**
     * Get all usage logs for the team.
     */
    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    /**
     * Get all API keys for the team.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}
