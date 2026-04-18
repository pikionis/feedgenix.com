<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks monthly usage for each translation provider (Microsoft, Google, DeepL, Grok).
 * Provider quotas are SYSTEM-WIDE, not per-team — the free tiers we consume are
 * FeedGenix's accounts, shared across every tenant. Per-team billing lives in usage_logs.
 */
class TranslationQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'year',
        'month',
        'chars_used',
        'chars_limit',
        'cost_eur',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'chars_used' => 'integer',
            'chars_limit' => 'integer',
            'cost_eur' => 'float',
        ];
    }

    public static function forPeriod(string $provider, int $year, int $month): self
    {
        return static::firstOrCreate(
            ['provider' => $provider, 'year' => $year, 'month' => $month],
            ['chars_used' => 0, 'chars_limit' => 0, 'cost_eur' => 0]
        );
    }
}
