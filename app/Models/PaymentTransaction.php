<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only payment ledger entry.
 *
 * @property string $id
 * @property string $billing_order_id
 * @property string $user_id
 * @property string $provider
 * @property PaymentTransactionType $type
 * @property PaymentTransactionStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string $provider_transaction_id
 * @property string|null $provider_event_id
 * @property string $idempotency_key
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $processed_at
 * @property string|null $payload_fingerprint
 * @property array<string, mixed>|null $metadata
 */
class PaymentTransaction extends BaseModel
{
    protected $table = 'payment_transactions';

    /** @var list<string> */
    protected $fillable = [
        'billing_order_id',
        'user_id',
        'provider',
        'type',
        'status',
        'amount_minor',
        'currency',
        'provider_transaction_id',
        'provider_event_id',
        'idempotency_key',
        'failure_code',
        'failure_message',
        'processed_at',
        'payload_fingerprint',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'amount_minor' => 'integer',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
