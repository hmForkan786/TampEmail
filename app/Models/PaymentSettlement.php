<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentSettlementStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payment_transaction_id
 * @property string $billing_order_id
 * @property string $provider
 * @property string|null $provider_settlement_id
 * @property PaymentSettlementStatus $status
 * @property int $gross_amount_minor
 * @property int|null $fee_amount_minor
 * @property int|null $tax_amount_minor
 * @property int|null $net_amount_minor
 * @property string $currency
 * @property Carbon|null $settled_at
 * @property Carbon|null $failed_at
 */
class PaymentSettlement extends BaseModel
{
    protected $fillable = [
        'payment_transaction_id', 'billing_order_id', 'provider', 'provider_settlement_id',
        'status', 'gross_amount_minor', 'fee_amount_minor', 'tax_amount_minor',
        'net_amount_minor', 'currency', 'expected_at', 'settled_at', 'failed_at',
        'failure_code', 'failure_message', 'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => PaymentSettlementStatus::class,
            'gross_amount_minor' => 'integer',
            'fee_amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'expected_at' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class);
    }
}
