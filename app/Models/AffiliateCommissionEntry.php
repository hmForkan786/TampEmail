<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only ledger row for affiliate commission accrual, holds, and payouts.
 *
 * @property string $id
 * @property string $affiliate_profile_id
 * @property string|null $conversion_id
 * @property string|null $withdrawal_id
 * @property AffiliateCommissionEntryType $entry_type
 * @property int $amount_minor
 * @property string $currency
 * @property AffiliateCommissionEntryStatus $status
 * @property Carbon|null $available_at
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $reason_code
 * @property array<string, mixed>|null $metadata
 * @property string|null $idempotency_key
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property-read AffiliateProfile $profile
 * @property-read AffiliateConversion|null $conversion
 * @property-read AffiliateWithdrawal|null $withdrawal
 * @property-read User|null $creator
 */
class AffiliateCommissionEntry extends BaseModel
{
    protected $table = 'affiliate_commission_entries';

    const UPDATED_AT = null;

    protected $fillable = [
        'affiliate_profile_id',
        'conversion_id',
        'withdrawal_id',
        'entry_type',
        'amount_minor',
        'currency',
        'status',
        'available_at',
        'reference_type',
        'reference_id',
        'reason_code',
        'metadata',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'entry_type' => AffiliateCommissionEntryType::class,
            'amount_minor' => 'integer',
            'status' => AffiliateCommissionEntryStatus::class,
            'available_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /** @return BelongsTo<AffiliateProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    /** @return BelongsTo<AffiliateConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversion::class, 'conversion_id');
    }

    /** @return BelongsTo<AffiliateWithdrawal, $this> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(AffiliateWithdrawal::class, 'withdrawal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->amount_minor > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount_minor < 0;
    }
}
