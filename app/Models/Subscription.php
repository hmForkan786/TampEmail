<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * User subscription lifecycle linked to a billing plan.
 *
 * @property string $id
 * @property string $user_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property BillingCycle $billing_cycle
 * @property Carbon $starts_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $cancelled_at
 * @property bool $auto_renew
 * @property string $price
 * @property string $currency
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Plan $plan
 * @property-read Collection<int, SubscriptionTransaction> $transactions
 * @property-read Collection<int, SubscriptionUsage> $usageRecords
 */
class Subscription extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subscriptions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'billing_cycle',
        'starts_at',
        'trial_ends_at',
        'ends_at',
        'cancelled_at',
        'auto_renew',
        'price',
        'currency',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
            'price' => 'decimal:2',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the user that owns the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the plan associated with the subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the payment transactions for the subscription.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }

    /**
     * Get the feature usage records for the subscription.
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }

    /**
     * Scope a query to only include active subscriptions.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Active);
    }

    /**
     * Scope a query to only include expired subscriptions.
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Expired);
    }

    /**
     * Scope a query to only include trial subscriptions.
     */
    #[Scope]
    protected function trial(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Trial);
    }

    /**
     * Determine whether the subscription is active.
     */
    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    /**
     * Determine whether the subscription is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired;
    }

    /**
     * Determine whether the subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trial;
    }

    /**
     * Determine whether the subscription is set to auto-renew.
     */
    public function autoRenews(): bool
    {
        return $this->auto_renew;
    }
}
