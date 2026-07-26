<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundNotification extends BaseModel
{
    protected $fillable = ['user_id', 'outbound_message_id', 'event_type', 'idempotency_key', 'payload', 'read_at', 'dismissed_at', 'email_queued_at', 'email_sent_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['payload' => 'array', 'read_at' => 'datetime', 'dismissed_at' => 'datetime', 'email_queued_at' => 'datetime', 'email_sent_at' => 'datetime']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class);
    }
}
