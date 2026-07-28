<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCheckoutRequest extends BaseModel
{
    protected $fillable = [
        'user_id', 'idempotency_key', 'request_fingerprint', 'billing_order_id',
        'gateway', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['expires_at' => 'datetime']);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }
}
