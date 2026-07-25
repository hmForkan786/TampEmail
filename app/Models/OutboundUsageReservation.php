<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundOperation;
use App\Enums\OutboundUsageReservationState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Atomic outbound usage reservation for a single outbound message.
 *
 * @property string $id
 * @property string $outbound_message_id
 * @property string $user_id
 * @property string|null $subscription_id
 * @property OutboundOperation $operation
 * @property string $idempotency_key
 * @property OutboundUsageReservationState $state
 * @property int $message_units
 * @property int $recipient_units
 * @property int $attachment_bytes
 * @property Carbon $reserved_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $released_at
 * @property Carbon|null $expires_at
 * @property string|null $release_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OutboundMessage $outboundMessage
 * @property-read User $user
 * @property-read Subscription|null $subscription
 */
class OutboundUsageReservation extends BaseModel
{
    protected $table = 'outbound_usage_reservations';

    /** @var list<string> */
    protected $fillable = [
        'outbound_message_id',
        'user_id',
        'subscription_id',
        'operation',
        'idempotency_key',
        'state',
        'message_units',
        'recipient_units',
        'attachment_bytes',
        'reserved_at',
        'committed_at',
        'released_at',
        'expires_at',
        'release_reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'operation' => OutboundOperation::class,
            'state' => OutboundUsageReservationState::class,
            'message_units' => 'integer',
            'recipient_units' => 'integer',
            'attachment_bytes' => 'integer',
            'reserved_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isReserved(): bool
    {
        return $this->state === OutboundUsageReservationState::Reserved;
    }

    public function isCommitted(): bool
    {
        return $this->state === OutboundUsageReservationState::Committed;
    }
}
