<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable commercial invoice record. Never a payment authority.
 *
 * @property string $id
 * @property string|null $invoice_number
 * @property string $billing_order_id
 * @property string|null $subscription_id
 * @property string $user_id
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property InvoiceStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string|null $content_fingerprint
 * @property array<string, mixed>|null $metadata
 * @property-read Collection<int, BillingInvoiceLineItem> $lineItems
 * @property-read BillingOrder $billingOrder
 * @property-read User $user
 */
class BillingInvoice extends BaseModel
{
    protected $table = 'billing_invoices';

    /** @var list<string> */
    protected $fillable = [
        'invoice_number',
        'billing_order_id',
        'subscription_id',
        'user_id',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'discount_minor',
        'total_minor',
        'status',
        'issued_at',
        'paid_at',
        'voided_at',
        'provider',
        'provider_reference',
        'content_fingerprint',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BillingInvoiceLineItem::class)->orderBy('position');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(BillingCreditNote::class);
    }
}
