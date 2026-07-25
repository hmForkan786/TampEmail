<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundDeliveryAttemptState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single outbound delivery attempt for one message.
 *
 * One row per (outbound_message_id, attempt_number). Never stores body,
 * full recipient lists, raw SMTP/provider responses, credentials, or
 * attachment content — only safe, coarse operational metadata.
 *
 * @property string $id
 * @property string $outbound_message_id
 * @property int $attempt_number
 * @property string|null $transport
 * @property OutboundDeliveryAttemptState $state
 * @property string|null $result
 * @property string|null $failure_category
 * @property string|null $provider_message_id
 * @property bool $ambiguous
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OutboundMessage $outboundMessage
 */
class OutboundDeliveryAttempt extends BaseModel
{
    protected $table = 'outbound_delivery_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'outbound_message_id',
        'attempt_number',
        'transport',
        'state',
        'result',
        'failure_category',
        'provider_message_id',
        'ambiguous',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'attempt_number' => 'integer',
            'state' => OutboundDeliveryAttemptState::class,
            'ambiguous' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
        ]);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class);
    }
}
