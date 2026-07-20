<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResetPeriod;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metered feature usage counter for a subscription billing period.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $feature_id
 * @property int $used_value
 * @property int|null $limit_value
 * @property ResetPeriod $reset_period
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $last_used_at
 * @property array<string, mixed>|null $metadata
 * @property-read Subscription $subscription
 * @property-read Feature $feature
 */
class SubscriptionUsage extends BaseModel
{
    protected $table = 'subscription_usage';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'feature_id',
        'used_value',
        'limit_value',
        'reset_period',
        'period_start',
        'period_end',
        'last_used_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'reset_period' => ResetPeriod::class,
            'used_value' => 'integer',
            'limit_value' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'last_used_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the subscription that owns the usage record.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the feature being metered.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /**
     * Scope a query to usage records in the current billing period.
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('period_end', '>=', now());
    }

    /**
     * Scope a query to usage records in an expired billing period.
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->where('period_end', '<', now());
    }

    /**
     * Scope a query to usage for the given feature.
     */
    #[Scope]
    protected function forFeature(Builder $query, string $featureId): void
    {
        $query->where('feature_id', $featureId);
    }

    /**
     * Determine whether the usage record has a limit.
     */
    public function hasLimit(): bool
    {
        return $this->limit_value !== null;
    }

    /**
     * Get the remaining usage allowance, or null when unlimited.
     */
    public function remaining(): ?int
    {
        if (! $this->hasLimit()) {
            return null;
        }

        return max(0, $this->limit_value - $this->used_value);
    }

    /**
     * Determine whether usage has exceeded the limit.
     */
    public function isExceeded(): bool
    {
        if (! $this->hasLimit()) {
            return false;
        }

        return $this->used_value > $this->limit_value;
    }

    /**
     * Get usage as a percentage of the limit, or null when unlimited.
     */
    public function usagePercentage(): ?float
    {
        if (! $this->hasLimit()) {
            return null;
        }

        if ($this->limit_value === 0) {
            return $this->used_value > 0 ? 100.0 : 0.0;
        }

        return round(($this->used_value / $this->limit_value) * 100, 2);
    }
}
