<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * @property string $id
 * @property string $user_id
 * @property string $plan_id
 * @property string|null $subscription_id
 * @property BillingOrderType $type
 * @property BillingOrderStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 * @property-read Collection<int, PaymentTransaction> $paymentTransactions
 */
class BillingOrder extends BaseModel
{
    protected $table = 'billing_orders';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'plan_id',
        'subscription_id',
        'type',
        'status',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'provider',
        'provider_reference',
        'idempotency_key',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => BillingOrderType::class,
            'status' => BillingOrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
