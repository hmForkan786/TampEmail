<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliateConversionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A qualifying order attributed to an affiliate, pending or approved for commission.
 *
 * @property string $id
 * @property string $affiliate_profile_id
 * @property string|null $attribution_id
 * @property string $referred_user_id
 * @property string $billing_order_id
 * @property string|null $subscription_id
 * @property string|null $invoice_id
 * @property AffiliateConversionStatus $status
 * @property int $order_amount_minor
 * @property string $currency
 * @property int $commission_amount_minor
 * @property array<string, mixed> $commission_plan_snapshot
 * @property Carbon $qualified_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $reversed_at
 * @property string|null $reason_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AffiliateProfile $profile
 * @property-read AffiliateAttribution|null $attribution
 * @property-read User|null $referredUser
 * @property-read BillingOrder $order
 * @property-read Collection<int, AffiliateCommissionEntry> $commissionEntries
 */
class AffiliateConversion extends BaseModel
{
    protected $table = 'affiliate_conversions';

    protected $fillable = [
        'affiliate_profile_id',
        'attribution_id',
        'referred_user_id',
        'billing_order_id',
        'subscription_id',
        'invoice_id',
        'status',
        'order_amount_minor',
        'currency',
        'commission_amount_minor',
        'commission_plan_snapshot',
        'qualified_at',
        'approved_at',
        'rejected_at',
        'reversed_at',
        'reason_code',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AffiliateConversionStatus::class,
            'order_amount_minor' => 'integer',
            'commission_amount_minor' => 'integer',
            'commission_plan_snapshot' => 'array',
            'qualified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reversed_at' => 'datetime',
        ]);
    }

    /** @return BelongsTo<AffiliateProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    /** @return BelongsTo<AffiliateAttribution, $this> */
    public function attribution(): BelongsTo
    {
        return $this->belongsTo(AffiliateAttribution::class, 'attribution_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** @return BelongsTo<BillingOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }

    /** @return HasMany<AffiliateCommissionEntry, $this> */
    public function commissionEntries(): HasMany
    {
        return $this->hasMany(AffiliateCommissionEntry::class, 'conversion_id');
    }

    public function isApproved(): bool
    {
        return $this->status === AffiliateConversionStatus::Approved;
    }
}
