<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateCommissionType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Commission structure applied to an affiliate's conversions.
 *
 * @property string $id
 * @property string $name
 * @property AffiliateCommissionPlanStatus $status
 * @property AffiliateCommissionType $commission_type
 * @property int|null $percentage_bps
 * @property int|null $fixed_amount_minor
 * @property string|null $currency
 * @property int|null $minimum_order_minor
 * @property int|null $maximum_commission_minor
 * @property int $cookie_window_days
 * @property int $commission_hold_days
 * @property bool $new_customer_only
 * @property bool $recurring_commission_enabled
 * @property int|null $recurring_cycles
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AffiliateProfile> $profiles
 */
class AffiliateCommissionPlan extends BaseModel
{
    protected $table = 'affiliate_commission_plans';

    protected $fillable = [
        'name',
        'status',
        'commission_type',
        'percentage_bps',
        'fixed_amount_minor',
        'currency',
        'minimum_order_minor',
        'maximum_commission_minor',
        'cookie_window_days',
        'commission_hold_days',
        'new_customer_only',
        'recurring_commission_enabled',
        'recurring_cycles',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AffiliateCommissionPlanStatus::class,
            'commission_type' => AffiliateCommissionType::class,
            'percentage_bps' => 'integer',
            'fixed_amount_minor' => 'integer',
            'minimum_order_minor' => 'integer',
            'maximum_commission_minor' => 'integer',
            'cookie_window_days' => 'integer',
            'commission_hold_days' => 'integer',
            'new_customer_only' => 'boolean',
            'recurring_commission_enabled' => 'boolean',
            'recurring_cycles' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ]);
    }

    /** @return HasMany<AffiliateProfile, $this> */
    public function profiles(): HasMany
    {
        return $this->hasMany(AffiliateProfile::class, 'commission_plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === AffiliateCommissionPlanStatus::Active;
    }

    public function isPercentageBased(): bool
    {
        return $this->commission_type === AffiliateCommissionType::Percentage;
    }
}
