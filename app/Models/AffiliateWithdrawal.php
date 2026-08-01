<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliatePayoutMethod;
use App\Enums\AffiliateWithdrawalStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Affiliate payout request and its review/approval/payment lifecycle.
 *
 * @property string $id
 * @property string $affiliate_profile_id
 * @property int $amount_minor
 * @property string $currency
 * @property AffiliateWithdrawalStatus $status
 * @property AffiliatePayoutMethod $payout_method
 * @property string|null $payout_details_snapshot_encrypted
 * @property string $idempotency_key
 * @property Carbon $requested_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $paid_by
 * @property Carbon|null $paid_at
 * @property string|null $external_reference
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AffiliateProfile $profile
 * @property-read User|null $reviewer
 * @property-read User|null $approver
 * @property-read User|null $payer
 * @property-read Collection<int, AffiliateCommissionEntry> $commissionEntries
 */
class AffiliateWithdrawal extends BaseModel
{
    protected $table = 'affiliate_withdrawals';

    protected $fillable = [
        'affiliate_profile_id',
        'amount_minor',
        'currency',
        'status',
        'payout_method',
        'payout_details_snapshot_encrypted',
        'idempotency_key',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'external_reference',
        'rejection_reason',
    ];

    protected $hidden = [
        'payout_details_snapshot_encrypted',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_minor' => 'integer',
            'status' => AffiliateWithdrawalStatus::class,
            'payout_method' => AffiliatePayoutMethod::class,
            'payout_details_snapshot_encrypted' => 'encrypted',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ]);
    }

    /** @return BelongsTo<AffiliateProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return HasMany<AffiliateCommissionEntry, $this> */
    public function commissionEntries(): HasMany
    {
        return $this->hasMany(AffiliateCommissionEntry::class, 'withdrawal_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            AffiliateWithdrawalStatus::Paid,
            AffiliateWithdrawalStatus::Rejected,
            AffiliateWithdrawalStatus::Cancelled,
        ], true);
    }
}
