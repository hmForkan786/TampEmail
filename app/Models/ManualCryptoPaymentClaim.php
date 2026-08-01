<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ManualCryptoClaimState;
use App\Enums\ManualCryptoEvidenceStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $billing_order_id
 * @property string $user_id
 * @property string $checkout_snapshot_id
 * @property string $network
 * @property string $txid
 * @property int $submitted_amount_units
 * @property string|null $screenshot_path
 * @property ManualCryptoClaimState $state
 * @property ManualCryptoEvidenceStatus $evidence_status
 * @property string|null $reviewer_id
 * @property string|null $decision_reason
 * @property string|null $provider_event_id
 * @property Carbon $submitted_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property-read BillingOrder $order
 * @property-read ManualCryptoCheckoutSnapshot $snapshot
 * @property-read Collection<int, ManualCryptoReviewEvent> $reviewEvents
 */
final class ManualCryptoPaymentClaim extends BaseModel
{
    protected $fillable = [
        'billing_order_id', 'user_id', 'checkout_snapshot_id', 'network', 'txid',
        'submitted_amount_units', 'screenshot_path', 'state', 'evidence_status',
        'reviewer_id', 'decision_reason', 'provider_event_id', 'submitted_at',
        'reviewed_at', 'approved_at', 'rejected_at',
    ];

    protected $hidden = ['screenshot_path'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'submitted_amount_units' => 'integer',
            'screenshot_path' => 'encrypted',
            'state' => ManualCryptoClaimState::class,
            'evidence_status' => ManualCryptoEvidenceStatus::class,
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime',
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ManualCryptoCheckoutSnapshot::class, 'checkout_snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ManualCryptoReviewEvent::class, 'claim_id')->orderBy('created_at');
    }
}
