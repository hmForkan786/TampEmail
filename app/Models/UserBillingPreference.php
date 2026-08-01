<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Billing metadata preferences (snapshot into new invoices only).
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $billing_email
 * @property string|null $invoice_name
 * @property string|null $invoice_address
 * @property string|null $invoice_locale
 * @property string|null $tax_identifier_encrypted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserBillingPreference extends Model
{
    use HasUuid;

    protected $table = 'user_billing_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'billing_email',
        'invoice_name',
        'invoice_address',
        'invoice_locale',
        'tax_identifier_encrypted',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'tax_identifier_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_identifier_encrypted' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
