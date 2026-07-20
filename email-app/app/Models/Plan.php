<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Subscription plan definition with pricing and feature entitlements.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $price_monthly
 * @property string $price_yearly
 * @property string $currency
 * @property bool $is_free
 * @property bool $is_active
 * @property int $display_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Feature> $features
 * @property-read Collection<int, Subscription> $subscriptions
 */
class Plan extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'plans';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_monthly',
        'price_yearly',
        'currency',
        'is_free',
        'is_active',
        'display_order',
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
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'is_free' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the features assigned to the plan.
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_plan')
            ->withPivot('feature_value')
            ->withTimestamps();
    }

    /**
     * Get the subscriptions associated with the plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Scope a query to only include active plans.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to order plans by display order.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('display_order');
    }

    /**
     * Determine whether the plan is free.
     */
    public function isFree(): bool
    {
        return $this->is_free;
    }

    /**
     * Determine whether the plan is paid.
     */
    public function isPaid(): bool
    {
        return ! $this->is_free;
    }

    /**
     * Get the monthly price for the plan.
     */
    public function monthlyPrice(): string
    {
        return (string) $this->price_monthly;
    }

    /**
     * Get the yearly price for the plan.
     */
    public function yearlyPrice(): string
    {
        return (string) $this->price_yearly;
    }
}
