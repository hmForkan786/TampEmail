<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $billing_order_id
 * @property string $wallet_id
 * @property string $wallet_address
 * @property string $asset
 * @property string $network
 * @property int $expected_amount_minor
 * @property string $currency
 * @property Carbon $expires_at
 */
final class ManualCryptoCheckoutSnapshot extends BaseModel
{
    protected $fillable = ['billing_order_id', 'wallet_id', 'wallet_address', 'asset', 'network', 'expected_amount_minor', 'currency', 'expires_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'wallet_address' => 'encrypted',
            'expected_amount_minor' => 'integer',
            'expires_at' => 'datetime',
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }
}
