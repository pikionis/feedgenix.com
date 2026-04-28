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
        'plan',
        'products_used_this_month',
        'billing_cycle_reset_at',
        // Cashier-managed columns — need to be fillable so webhook handlers
        // and our own SubscriptionController can write them.
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
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

    /**
     * True while the team's active subscription is still in its Stripe
     * trial window. New merchants get 7 days; trial limits apply during
     * that window, normal tier limits apply afterwards.
     */
    public function isOnTrial(): bool
    {
        $subscription = $this->subscription();
        return $subscription !== null && $subscription->onTrial();
    }

    /**
     * Calendar days remaining in the active trial. Returns 0 if not on trial.
     * Useful for the "X days left" banner in the UI.
     */
    public function trialDaysRemaining(): int
    {
        $subscription = $this->subscription();
        if (!$subscription || !$subscription->onTrial() || !$subscription->trial_ends_at) {
            return 0;
        }
        // ceil() so a partial day still reads as "1 day left", not "0".
        return max(0, (int) ceil(now()->diffInDays($subscription->trial_ends_at, false)));
    }

    /**
     * Resolve the limits + features that apply to this team RIGHT NOW.
     * Trial overlays its own limits on top of the underlying plan;
     * once trial ends, the plan's tier kicks in.
     *
     * @return array{
     *   products_per_month: int,
     *   target_languages: int,
     *   team_members: int,
     *   features: list<string>,
     * }
     */
    public function effectiveLimits(): array
    {
        if ($this->isOnTrial()) {
            return (array) config('feedgenix.trial.limits');
        }
        $plan = $this->plan ?? 'free';
        return (array) (config("feedgenix.tiers.{$plan}") ?? config('feedgenix.tiers.free'));
    }
}
