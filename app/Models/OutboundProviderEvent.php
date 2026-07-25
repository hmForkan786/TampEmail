<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundProviderEventType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only sanitized provider delivery event for outbound messages.
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $provider_message_id
 * @property string|null $outbound_message_id
 * @property OutboundProviderEventType $event_type
 * @property string $normalized_status
 * @property Carbon $received_at
 * @property Carbon|null $provider_event_at
 * @property Carbon|null $processed_at
 * @property string $signature_state
 * @property array<string, mixed>|null $metadata
 * @property string|null $outcome
 * @property int $reconciliation_attempts
 * @property Carbon|null $terminal_unmatched_at
 * @property Carbon|null $created_at
 */
class OutboundProviderEvent extends BaseModel
{
    protected $table = 'outbound_provider_events';

    public $timestamps = true;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'provider_message_id',
        'outbound_message_id',
        'event_type',
        'normalized_status',
        'received_at',
        'provider_event_at',
        'processed_at',
        'signature_state',
        'metadata',
        'outcome',
        'reconciliation_attempts',
        'terminal_unmatched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'event_type' => OutboundProviderEventType::class,
            'received_at' => 'datetime',
            'provider_event_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
            'reconciliation_attempts' => 'integer',
            'terminal_unmatched_at' => 'datetime',
        ]);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class, 'outbound_message_id');
    }
}
