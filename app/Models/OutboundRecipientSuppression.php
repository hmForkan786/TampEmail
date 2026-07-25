<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recipient suppression entry preventing outbound delivery.
 *
 * @property string $id
 * @property string $recipient_hash
 * @property string|null $recipient_encrypted
 * @property string $masked_recipient
 * @property string $scope_type
 * @property string|null $scope_id
 * @property string $reason
 * @property string $source
 * @property string|null $provider
 * @property string|null $source_event_id
 * @property bool $active
 * @property Carbon $suppressed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $removed_at
 * @property string|null $removed_by
 */
class OutboundRecipientSuppression extends BaseModel
{
    protected $table = 'outbound_recipient_suppressions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'recipient_hash',
        'recipient_encrypted',
        'masked_recipient',
        'scope_type',
        'scope_id',
        'reason',
        'source',
        'provider',
        'source_event_id',
        'active',
        'suppressed_at',
        'expires_at',
        'removed_at',
        'removed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'recipient_encrypted' => 'encrypted',
            'active' => 'boolean',
            'suppressed_at' => 'datetime',
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
        ]);
    }

    public function removedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
