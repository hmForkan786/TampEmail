<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliatePayoutMethod;
use App\Enums\AffiliateProfileStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Affiliate marketing account linked to a platform user.
 *
 * @property string $id
 * @property string $user_id
 * @property string $affiliate_code
 * @property AffiliateProfileStatus $status
 * @property string|null $commission_plan_id
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $closed_at
 * @property AffiliatePayoutMethod|null $payout_method
 * @property string|null $payout_details_encrypted
 * @property string|null $application_notes
 * @property string|null $promotion_channel
 * @property string|null $website_url
 * @property string|null $audience_description
 * @property string|null $expected_traffic
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read AffiliateCommissionPlan|null $plan
 * @property-read User|null $approver
 * @property-read Collection<int, AffiliateAttribution> $attributions
 * @property-read Collection<int, AffiliateConversion> $conversions
 * @property-read Collection<int, AffiliateCommissionEntry> $commissionEntries
 * @property-read Collection<int, AffiliateWithdrawal> $withdrawals
 */
class AffiliateProfile extends BaseModel
{
    protected $table = 'affiliate_profiles';

    protected $fillable = [
        'user_id',
        'affiliate_code',
        'status',
        'commission_plan_id',
        'approved_by',
        'approved_at',
        'suspended_at',
        'rejected_at',
        'closed_at',
        'payout_method',
        'payout_details_encrypted',
        'application_notes',
        'promotion_channel',
        'website_url',
        'audience_description',
        'expected_traffic',
        'metadata',
    ];

    protected $hidden = [
        'payout_details_encrypted',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AffiliateProfileStatus::class,
            'payout_method' => AffiliatePayoutMethod::class,
            'payout_details_encrypted' => 'encrypted',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'rejected_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AffiliateCommissionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AffiliateCommissionPlan::class, 'commission_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<AffiliateAttribution, $this> */
    public function attributions(): HasMany
    {
        return $this->hasMany(AffiliateAttribution::class);
    }

    /** @return HasMany<AffiliateConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    /** @return HasMany<AffiliateCommissionEntry, $this> */
    public function commissionEntries(): HasMany
    {
        return $this->hasMany(AffiliateCommissionEntry::class);
    }

    /** @return HasMany<AffiliateWithdrawal, $this> */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class);
    }

    public function isActive(): bool
    {
        return $this->status === AffiliateProfileStatus::Active;
    }

    public function canReceiveAttribution(): bool
    {
        return $this->isActive();
    }

    public function canWithdraw(): bool
    {
        return $this->isActive();
    }

    public function normalizedCode(): string
    {
        return Str::lower((string) $this->affiliate_code);
    }
}
