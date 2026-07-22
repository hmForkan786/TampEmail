<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Payment transaction record for a subscription billing event.
 *
 * @property string $id
 * @property string $subscription_id
 * @property PaymentGateway $gateway
 * @property PaymentStatus $payment_status
 * @property string $amount
 * @property string $tax_amount
 * @property string $discount_amount
 * @property string $currency
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property array<string, mixed>|null $gateway_response
 * @property array<string, mixed>|null $metadata
 * @property-read Subscription $subscription
 */
class SubscriptionTransaction extends BaseModel
{
    protected $table = 'subscription_transactions';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'gateway',
        'gateway_transaction_id',
        'invoice_number',
        'payment_status',
        'amount',
        'tax_amount',
        'discount_amount',
        'currency',
        'payment_method',
        'paid_at',
        'refunded_at',
        'gateway_response',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'gateway' => PaymentGateway::class,
            'payment_status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'gateway_response' => 'array',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the subscription that owns the transaction.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Scope a query to only include paid transactions.
     */
    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Paid);
    }

    /**
     * Scope a query to only include pending transactions.
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Pending);
    }

    /**
     * Scope a query to only include refunded transactions.
     */
    #[Scope]
    protected function refunded(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Refunded);
    }

    /**
     * Scope a query to transactions from the given payment gateway.
     */
    #[Scope]
    protected function gateway(Builder $query, PaymentGateway $gateway): void
    {
        $query->where('gateway', $gateway);
    }

    /**
     * Determine whether the transaction is paid.
     */
    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }

    /**
     * Determine whether the transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->payment_status === PaymentStatus::Pending;
    }

    /**
     * Determine whether the transaction is refunded.
     */
    public function isRefunded(): bool
    {
        return $this->payment_status === PaymentStatus::Refunded;
    }

    /**
     * Get the net charged amount after tax and discounts.
     */
    public function netAmount(): string
    {
        return bcsub(
            bcadd((string) $this->amount, (string) $this->tax_amount, 2),
            (string) $this->discount_amount,
            2
        );
    }
}
