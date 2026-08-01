<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCheckoutSessionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $billing_order_id
 * @property string $user_id
 * @property string $provider
 * @property BillingCheckoutSessionStatus $status
 * @property string|null $provider_session_id
 * @property string|null $provider_reference
 * @property string|null $checkout_url
 * @property string $request_fingerprint
 * @property Carbon|null $expires_at
 */
class BillingCheckoutSession extends BaseModel
{
    protected $fillable = [
        'billing_order_id', 'user_id', 'provider', 'status', 'provider_session_id',
        'provider_reference', 'checkout_url', 'request_fingerprint', 'expires_at',
        'last_error_code', 'last_error_message', 'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => BillingCheckoutSessionStatus::class,
            'checkout_url' => 'encrypted',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }
}
